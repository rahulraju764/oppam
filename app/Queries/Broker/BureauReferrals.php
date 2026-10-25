<?php

declare(strict_types=1);

namespace App\Queries\Broker;

use App\Enums\ReferralSource;
use App\Enums\ReferralStage;
use App\Enums\ReferralStatus;
use App\Models\BrokerReferral;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * A bureau's own referrals (B.11 /broker/referrals, R-M13-6): scoped by the caller's broker id —
 * never one from input — and limited to members who proved their mobile (an unproved sign-up is
 * released later and never counts). Only privacy-safe columns are selected (R-M13-7): the profile
 * code, join date, stage and commission — never names, photos, contact details or activity.
 */
final class BureauReferrals
{
    public const PER_PAGE = 25;

    /** @return LengthAwarePaginator<int, BrokerReferral> */
    public function page(string $brokerId, ?ReferralStage $stage): LengthAwarePaginator
    {
        return $this->base($brokerId)
            ->when($stage !== null, fn (Builder $query) => $this->whereStage($query, $stage))
            ->select([
                'broker_referrals.id', 'broker_referrals.status', 'broker_referrals.commission_paise', 'broker_referrals.created_at',
                'profiles.code as profile_code', 'profiles.published_at as profile_published_at',
            ])
            ->orderByDesc('broker_referrals.created_at')->orderByDesc('broker_referrals.id')
            ->paginate(self::PER_PAGE)
            ->through(fn (BrokerReferral $row): BrokerReferral => $row->setAttribute(
                'stage', ReferralStage::of($row->status, $row->getAttribute('profile_published_at') !== null),
            ));
    }

    /** @return array{total: int, this_month: int, converted: int} for the dashboard widget (B.4) */
    public function stats(string $brokerId): array
    {
        $row = $this->base($brokerId)->toBase()->selectRaw(
            'COUNT(*) AS total, SUM(CASE WHEN broker_referrals.created_at >= ? THEN 1 ELSE 0 END) AS this_month, '
            .'SUM(CASE WHEN broker_referrals.status IN (?, ?) THEN 1 ELSE 0 END) AS converted',
            [now()->timezone((string) config('oppam.display_timezone'))->startOfMonth()->utc(), ReferralStatus::Earned->value, ReferralStatus::Paid->value],
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'this_month' => (int) ($row->this_month ?? 0),
            'converted' => (int) ($row->converted ?? 0),
        ];
    }

    /** @return Builder<BrokerReferral> */
    private function base(string $brokerId): Builder
    {
        return BrokerReferral::query()
            ->join('profiles', 'profiles.id', '=', 'broker_referrals.profile_id')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('broker_referrals.broker_id', $brokerId)
            ->where('broker_referrals.source', ReferralSource::Referral)
            ->whereNotNull('users.phone_verified_at');
    }

    /**
     * @param  Builder<BrokerReferral>  $query
     * @return Builder<BrokerReferral>
     */
    private function whereStage(Builder $query, ReferralStage $stage): Builder
    {
        return match ($stage) {
            ReferralStage::Converted => $query->whereIn('broker_referrals.status', [ReferralStatus::Earned, ReferralStatus::Paid]),
            ReferralStage::Refunded => $query->where('broker_referrals.status', ReferralStatus::Void),
            ReferralStage::Live => $query->where('broker_referrals.status', ReferralStatus::Pending)->whereNotNull('profiles.published_at'),
            ReferralStage::Joined => $query->where('broker_referrals.status', ReferralStatus::Pending)->whereNull('profiles.published_at'),
        };
    }
}
