<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Enums\BrokerSignal;
use App\Enums\ConsentDisputeStatus;
use App\Enums\ModerationHold;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\SettingKey;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerImportBatch;
use App\Models\BrokerProfileExport;
use App\Models\BrokerReferral;
use App\Models\BrokerStaff;
use App\Models\LoginEvent;
use App\Models\ModerationItem;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The A07 fraud & quality detectors (§11A B.12 signal table; P7.8c, owner 2026-10-29 / 2026-10-30).
 * Each looks at one bureau with grouped queries and returns the details of a signal that is present
 * now — codes and counts only, never names, phones or IPs — or nothing. Thresholds are A15 settings.
 * What happens next (open the signal, hold payouts, tell the Owner) is RecordFraudSignals' business.
 */
final class FraudSignals
{
    /** @return array<string, array<string, mixed>> BrokerSignal value => details, for the signals present now */
    public function detect(Broker $broker): array
    {
        $found = [];
        foreach ([
            BrokerSignal::SharedSignups->value => $this->sharedSignups($broker),
            BrokerSignal::IncompleteReferrals->value => $this->incompleteReferrals($broker),
            BrokerSignal::PayThenRefund->value => $this->payThenRefund($broker),
            BrokerSignal::SelfReferral->value => $this->selfReferral($broker),
            BrokerSignal::ReferralSpike->value => $this->referralSpike($broker),
            BrokerSignal::ExportPattern->value => $this->exportPattern($broker),
            BrokerSignal::HighRejection->value => $this->highRejection($broker),
            BrokerSignal::ConsentDisputes->value => $this->consentDisputes($broker),
            BrokerSignal::ImportMobilePattern->value => $this->batchFlag($broker, 'mobile_pattern'),
            BrokerSignal::ImportPhotoMatch->value => $this->batchFlag($broker, 'photo_matches'),
            BrokerSignal::StaffDevices->value => $this->staffDevices($broker),
        ] as $type => $details) {
            if ($details !== null) {
                $found[$type] = $details;
            }
        }

        return $found;
    }

    /** @return array<string, int>|null the largest group of the last 30 days' referrals on one device / IP / network */
    public function sharedSignups(Broker $broker): ?array
    {
        $limit = Settings::int(SettingKey::BrokerSignalSharedSignups);
        $largest = fn (string $column): int => (int) BrokerReferral::query()->where('broker_id', $broker->id)
            ->where('source', ReferralSource::Referral)->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull($column)->groupBy($column)->selectRaw('count(*) as n')->orderByDesc('n')->value('n');

        $counts = ['device' => $largest('signup_device_hash'), 'ip' => $largest('signup_ip_hash'), 'network' => $largest('signup_net_hash')];

        // A mobile network shares addresses between many people (CGNAT): the /24 needs three times as many.
        return $counts['device'] >= $limit || $counts['ip'] >= $limit || $counts['network'] >= $limit * 3 ? $counts : null;
    }

    /** @return array<string, int>|null referrals older than 30 days whose profile never went live */
    public function incompleteReferrals(Broker $broker): ?array
    {
        $row = BrokerReferral::query()->from('broker_referrals as r')->join('profiles as p', 'p.id', '=', 'r.profile_id')
            ->where('r.broker_id', $broker->id)->where('r.source', ReferralSource::Referral->value)
            ->where('r.created_at', '<', now()->subDays(30))
            ->selectRaw('count(*) as total, sum(case when p.published_at is null then 1 else 0 end) as never_live')
            ->toBase()->first();
        $total = (int) ($row->total ?? 0);
        $never = (int) ($row->never_live ?? 0);

        return $total >= 10 && $never * 100 >= $total * Settings::int(SettingKey::BrokerSignalIncompletePct)
            ? ['referrals' => $total, 'never_live' => $never] : null;
    }

    /** @return array<string, int>|null referred members who paid and were refunded within 7 days, in 90 days */
    public function payThenRefund(Broker $broker): ?array
    {
        $count = BrokerReferral::query()->where('broker_id', $broker->id)
            ->where('status', ReferralStatus::Void)->where('void_reason', ReferralVoidReason::Refund)
            ->where('voided_at', '>=', now()->subDays(90))->whereNotNull('earned_at')
            ->whereRaw('voided_at <= earned_at + interval 7 day')->count();

        return $count >= Settings::int(SettingKey::BrokerSignalRefundCount) ? ['refunded' => $count] : null;
    }

    /** @return array<string, mixed>|null referrals (90 days) signed up on a device or IP the bureau's own logins use */
    public function selfReferral(Broker $broker): ?array
    {
        $logins = BrokerStaff::query()->where('broker_id', $broker->id)->pluck('user_id');
        if ($logins->isEmpty()) {
            return null;
        }

        $since = now()->subDays(90);
        $devices = LoginEvent::query()->whereIn('user_id', $logins)->where('created_at', '>=', $since)->distinct()->pluck('device_hash');
        $ips = LoginEvent::query()->whereIn('user_id', $logins)->where('created_at', '>=', $since)->whereNotNull('ip_address')
            ->distinct()->limit(500)->pluck('ip_address')->map(fn ($ip): string => SignupFingerprints::ipHash((string) $ip));

        $matches = BrokerReferral::query()->where('broker_referrals.broker_id', $broker->id)->where('broker_referrals.source', ReferralSource::Referral)
            ->where('broker_referrals.created_at', '>=', $since)
            ->where(fn ($q) => $q->whereIn('broker_referrals.signup_device_hash', $devices)->orWhereIn('broker_referrals.signup_ip_hash', $ips))
            ->join('profiles', 'profiles.id', '=', 'broker_referrals.profile_id');
        $count = (clone $matches)->count();
        // The 20 newest, always in the same order, and the total — so a rescan never mistakes a reshuffle for new evidence.
        $codes = $matches->orderByDesc('broker_referrals.created_at')->orderByDesc('broker_referrals.id')->limit(20)->pluck('profiles.code');

        return $count > 0 ? ['matches' => $count, 'profiles' => $codes->values()->all()] : null;
    }

    /** @return array{month: string, this_month: int, monthly_average: int}|null this IST month's referrals against the 3 previous months' average */
    public function referralSpike(Broker $broker): ?array
    {
        $tz = (string) config('oppam.display_timezone');
        $month = CarbonImmutable::now($tz)->startOfMonth();
        if ($broker->activated_at === null || $broker->activated_at->gt($month->subMonths(3))) {
            return null;   // a new bureau ramps up: no history to compare with
        }

        $count = fn (CarbonImmutable $from, CarbonImmutable $to): int => BrokerReferral::query()->where('broker_id', $broker->id)
            ->where('source', ReferralSource::Referral)->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count();

        $now = $count($month, $month->addMonth());
        $average = (int) ceil($count($month->subMonths(3), $month) / 3);

        return $now >= Settings::int(SettingKey::BrokerSignalSpikeMinimum) && $now >= max(1, $average) * Settings::int(SettingKey::BrokerSignalSpikeMultiplier)
            ? ['month' => $month->format('Y-m'), 'this_month' => $now, 'monthly_average' => $average] : null;
    }

    /** @return array<string, int>|null last 7 days: hours with a login over the hourly limit; profiles exported twice in an hour */
    public function exportPattern(Broker $broker): ?array
    {
        $base = fn () => BrokerProfileExport::query()->where('broker_id', $broker->id)->where('created_at', '>=', now()->subDays(7));
        $hour = "date_format(created_at, '%Y-%m-%d %H')";

        $burstHours = DB::query()->fromSub($base()->selectRaw("user_id, {$hour} as h")->groupBy('user_id', DB::raw($hour))
            ->havingRaw('count(*) > ?', [Settings::int(SettingKey::BrokerExportAnomalyPerHour)]), 'b')->count();
        $repeats = DB::query()->fromSub($base()->selectRaw("profile_id, {$hour} as h")->groupBy('profile_id', DB::raw($hour))
            ->havingRaw('count(*) > 1'), 'r')->count();

        return $burstHours > 0 || $repeats >= 10 ? ['burst_hours' => $burstHours, 'repeated_profiles' => $repeats] : null;
    }

    /** @return list<string> reason_category markers of items that were held or closed without a decision */
    public static function notDecisions(): array
    {
        return [...collect(ModerationHold::cases())->flatMap(fn (ModerationHold $h): array => [$h->value, $h->escalated()])->all(), 'STALE'];
    }

    /** @return array<string, int>|null A04 decisions on the bureau's new profiles in 30 days */
    public function highRejection(Broker $broker): ?array
    {
        $row = ModerationItem::query()->from('moderation_items as m')->join('profiles as p', 'p.id', '=', 'm.profile_id')
            ->where('p.managed_by_broker_id', $broker->id)->where('m.type', ModerationItemType::ProfileNew->value)
            ->whereIn('m.status', [ModerationStatus::Approved->value, ModerationStatus::Rejected->value])
            // Real rejections carry their reason here; items an admin action held, or closed as stale, are not decisions.
            ->where(fn ($q) => $q->whereNull('m.reason_category')->orWhereNotIn('m.reason_category', self::notDecisions()))
            ->where('m.decided_at', '>=', now()->subDays(30))
            ->selectRaw('count(*) as decided, sum(case when m.status = ? then 1 else 0 end) as rejected', [ModerationStatus::Rejected->value])
            ->toBase()->first();
        $decided = (int) ($row->decided ?? 0);
        $rejected = (int) ($row->rejected ?? 0);

        return $decided >= 10 && $rejected * 100 >= $decided * Settings::int(SettingKey::BrokerSignalRejectionPct)
            ? ['decided' => $decided, 'rejected' => $rejected] : null;
    }

    /** @return array<string, int>|null open disputes, and upheld ones in the dispute window */
    public function consentDisputes(Broker $broker): ?array
    {
        $open = BrokerConsentDispute::query()->where('broker_id', $broker->id)->where('status', ConsentDisputeStatus::Open)->count();
        $upheld = BrokerConsentDispute::query()->where('broker_id', $broker->id)->where('status', ConsentDisputeStatus::Upheld)
            ->where('decided_at', '>=', now()->subDays(Settings::int(SettingKey::BrokerDisputeWindowDays)))->count();

        return $open + $upheld > 0 ? ['open' => $open, 'upheld' => $upheld] : null;
    }

    /** @return array<string, mixed>|null the bureau's batches of the last 30 days that ImportBatchSignals flagged */
    public function batchFlag(Broker $broker, string $flag): ?array
    {
        $batches = BrokerImportBatch::query()->where('broker_id', $broker->id)->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('signal_flags')->get(['code', 'signal_flags'])
            ->filter(fn (BrokerImportBatch $b): bool => (int) (($b->signal_flags ?? [])[$flag] ?? 0) > 0);

        return $batches->isNotEmpty()
            ? ['batches' => $batches->pluck('code')->values()->all(), 'rows' => $batches->sum(fn (BrokerImportBatch $b): int => (int) ($b->signal_flags[$flag] ?? 0))]
            : null;
    }

    /** @return array<string, mixed>|null staff logins used from many devices or IP addresses in 7 days */
    public function staffDevices(Broker $broker): ?array
    {
        $limit = Settings::int(SettingKey::BrokerSignalStaffDevices);
        $staff = BrokerStaff::query()->where('broker_id', $broker->id)->pluck('code', 'user_id');
        if ($staff->isEmpty()) {
            return null;
        }

        $flagged = LoginEvent::query()->whereIn('user_id', $staff->keys())->where('created_at', '>=', now()->subDays(7))
            ->groupBy('user_id')->selectRaw('user_id, count(distinct device_hash) as devices, count(distinct ip_address) as ips')
            ->havingRaw('count(distinct device_hash) >= ? or count(distinct ip_address) >= ?', [$limit, $limit * 2])
            ->toBase()->get()
            ->map(fn (object $row): string => (string) $staff[$row->user_id]);

        return $flagged->isNotEmpty() ? ['staff' => $flagged->values()->all()] : null;
    }
}
