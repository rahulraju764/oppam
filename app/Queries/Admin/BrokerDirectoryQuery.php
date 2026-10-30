<?php

declare(strict_types=1);

namespace App\Queries\Admin;

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\Broker;
use App\Models\Profile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The A07 broker directory (§11A B.12): every bureau with its B.12 columns computed in one query —
 * referrals (total / converted), managed profiles (created / active now / claimed), commission
 * (earned unpaid / paid, attributions still pending), staff seats used (ACTIVE staff + open
 * invitations, as StaffSeats counts them), imports paused, payouts held, open fraud / quality signals (P7.8c). Search by code, name,
 * bureau name or any 4+ digits of the phone; filters by status, KYC, district and flags. Sort keys
 * come from an allow-list.
 */
final class BrokerDirectoryQuery
{
    public const SORTS = ['newest', 'oldest', 'code', 'earned'];

    /**
     * @param  array{q?: string, status?: string, kyc?: string, district?: string, flag?: string, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Broker>
     */
    public function page(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage);
    }

    /**
     * @param  array{q?: string, status?: string, kyc?: string, district?: string, flag?: string, sort?: string}  $filters
     * @return Builder<Broker>
     */
    public function query(array $filters): Builder
    {
        $query = Broker::query()
            ->with('district:id,label')
            ->withCount([
                'referrals as referrals_total' => fn (Builder $q) => $q->where('source', ReferralSource::Referral),
                'referrals as referrals_converted' => fn (Builder $q) => $q->where('source', ReferralSource::Referral)
                    ->whereIn('status', [ReferralStatus::Earned, ReferralStatus::Paid]),
                'referrals as referrals_pending' => fn (Builder $q) => $q->where('status', ReferralStatus::Pending),
                'referrals as managed_created' => fn (Builder $q) => $q->where('source', ReferralSource::Managed),
                'referrals as managed_claimed' => fn (Builder $q) => $q->where('source', ReferralSource::Managed)
                    ->whereIn('profile_id', Profile::withTrashed()->whereNotNull('claimed_at')->select('id')),
                'managedProfiles as managed_active' => fn (Builder $q) => $q->where('status', ProfileStatus::Active),
                'staff as staff_active' => fn (Builder $q) => $q->where('role', '!=', BureauRole::Owner->value)
                    ->where('status', BureauStaffStatus::Active),
                'staffInvitations as invitations_open' => fn (Builder $q) => $q->whereNull('accepted_at')->where('expires_at', '>', now()),
                'fraudSignals as signals_open' => fn (Builder $q) => $q->whereNull('resolved_at'),   // P7.8c (B.12)
            ])
            ->withSum(['referrals as commission_earned_paise' => fn (Builder $q) => $q->where('status', ReferralStatus::Earned)], 'commission_paise')
            ->withSum(['referrals as commission_paid_paise' => fn (Builder $q) => $q->where('status', ReferralStatus::Paid)], 'commission_paise');

        $this->filter($query, $filters);

        return match ($filters['sort'] ?? 'newest') {
            'oldest' => $query->orderBy('created_at')->orderBy('id'),
            'code' => $query->orderBy('code'),
            'earned' => $query->orderByDesc('commission_earned_paise')->orderBy('code'),
            default => $query->orderByDesc('created_at')->orderByDesc('id'),
        };
    }

    /**
     * @param  Builder<Broker>  $query
     * @param  array{q?: string, status?: string, kyc?: string, district?: string, flag?: string, sort?: string}  $filters
     */
    private function filter(Builder $query, array $filters): void
    {
        $term = trim($filters['q'] ?? '');
        if ($term !== '') {
            $digits = preg_replace('/\D+/', '', $term) ?? '';
            $query->where(function (Builder $q) use ($term, $digits): void {
                $q->where('code', strtoupper(str_replace(' ', '', $term)))
                    ->orWhere('name', 'like', '%'.addcslashes($term, '%_\\').'%')
                    ->orWhere('bureau_name', 'like', '%'.addcslashes($term, '%_\\').'%');
                if (strlen($digits) >= 4) {
                    $q->orWhere('phone', 'like', '%'.$digits.'%');
                }
            });
        }

        if (($status = BrokerStatus::tryFrom($filters['status'] ?? '')) !== null) {
            $query->where('status', $status);
        }
        if (($kyc = BrokerKycStatus::tryFrom($filters['kyc'] ?? '')) !== null) {
            $query->where('kyc_status', $kyc);
        }
        if (ctype_digit($filters['district'] ?? '')) {
            $query->where('district_id', (int) $filters['district']);
        }

        match ($filters['flag'] ?? '') {
            'imports_paused' => $query->whereNotNull('imports_paused_at'),
            'payouts_held' => $query->whereNotNull('payouts_held_at'),
            'admin_created' => $query->whereNotNull('created_by_admin_id'),
            'signals' => $query->whereHas('fraudSignals', fn (Builder $q) => $q->whereNull('resolved_at')),
            default => null,
        };
    }
}
