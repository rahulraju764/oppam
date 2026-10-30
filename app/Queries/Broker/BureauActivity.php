<?php

declare(strict_types=1);

namespace App\Queries\Broker;

use App\Enums\AuditActorType;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use App\Models\BrokerImportBatch;
use App\Models\BrokerStaff;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The bureau's activity log (§11A B.8 "bureau-scoped view of audit_logs — who created, edited,
 * imported, exported, accepted/declined what and when; filter by staff and date"): the `broker.*`
 * audit rows written by THIS bureau's logins (current and former staff rows — never another
 * bureau's or anyone else's). Filters arrive allow-listed: a staff code of this bureau, IST dates,
 * an action group. Rows show codes and labels only (subject_label is a code or a masked mobile).
 */
final class BureauActivity
{
    public const PER_PAGE = 25;

    /** Action group => the audit actions it covers (prefix match with a trailing '_' or exact). */
    public const GROUPS = [
        'profiles' => ['broker.profile_created', 'broker.profile_submitted', 'broker.profile_deactivate', 'broker.profile_activate',
            'broker.profile_delete', 'broker.profile_restore', 'broker.profile_client_phone_changed', 'broker.profiles_assigned'],
        'inbox' => ['broker.interest_accepted', 'broker.interest_declined', 'broker.interest_sent'],
        'exports' => ['broker.profile_exported'],
        'notes' => ['broker.follow_up_completed'],
        'staff' => ['broker.staff_invited', 'broker.staff_invite_cancelled', 'broker.staff_joined', 'broker.staff_permissions_changed', 'broker.staff_deactivated'],
    ];

    /**
     * @param  array{staff?: string, from?: string, to?: string, group?: string}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function page(BrokerStaff $viewer, array $filters): LengthAwarePaginator
    {
        return $this->query($viewer, $filters)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'actor_id', 'action', 'subject_label', 'after', 'created_at']);
    }

    /**
     * @param  array{staff?: string, from?: string, to?: string, group?: string}  $filters
     * @return Builder<AuditLog>
     */
    public function query(BrokerStaff $viewer, array $filters = []): Builder
    {
        return $this->forBureau($viewer->broker_id, $filters);
    }

    /**
     * A07 Activity tab (§11A B.12 "audit timeline"): the same bureau rows, plus — unless a staff filter
     * is set — what A07 did to the bureau (rows whose subject is the bureau, or one of its staff
     * logins, import batches or consent disputes). Admin-only; the bureau's own page never shows admin rows.
     *
     * @param  array{staff?: string, from?: string, to?: string, group?: string}  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function adminPage(Broker $broker, array $filters): LengthAwarePaginator
    {
        $withAdmin = ($filters['staff'] ?? '') === '' && ($filters['group'] ?? '') === '';

        return $this->forBureau($broker->id, $filters, $withAdmin ? $broker->id : null)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE, ['id', 'actor_type', 'actor_id', 'actor_label', 'action', 'subject_label', 'after', 'reason', 'created_at']);
    }

    /**
     * @param  array{staff?: string, from?: string, to?: string, group?: string}  $filters
     * @return Builder<AuditLog>
     */
    private function forBureau(string $brokerId, array $filters, ?string $alsoSubjectBrokerId = null): Builder
    {
        $logins = BrokerStaff::query()->where('broker_id', $brokerId)
            ->when(($filters['staff'] ?? '') !== '', fn ($q) => $q->where('code', $filters['staff']))
            ->pluck('user_id')->all();

        $tz = (string) config('oppam.display_timezone');
        $from = self::date($filters['from'] ?? null, $tz)?->startOfDay()->utc();
        $to = self::date($filters['to'] ?? null, $tz)?->endOfDay()->utc();
        $group = self::GROUPS[$filters['group'] ?? ''] ?? null;

        return AuditLog::query()
            ->where(fn (Builder $q) => $q
                ->where(fn (Builder $own) => $own->where('actor_type', AuditActorType::User->value)
                    ->whereIn('actor_id', $logins)
                    ->where('action', 'like', 'broker.%'))
                ->when($alsoSubjectBrokerId !== null, fn (Builder $q) => $q->orWhere(fn (Builder $admin) => $admin
                    ->where('subject_type', class_basename(Broker::class))->where('subject_id', $alsoSubjectBrokerId))
                    // A07's actions on the bureau's staff logins, import batches and consent disputes.
                    ->orWhere(fn (Builder $admin) => $admin->where('action', 'like', 'brokers.%')->where(fn (Builder $s) => $s
                        ->where(fn (Builder $x) => $x->where('subject_type', class_basename(BrokerStaff::class))
                            ->whereIn('subject_id', BrokerStaff::query()->where('broker_id', $alsoSubjectBrokerId)->select('id')))
                        ->orWhere(fn (Builder $x) => $x->where('subject_type', class_basename(BrokerImportBatch::class))
                            ->whereIn('subject_id', BrokerImportBatch::query()->where('broker_id', $alsoSubjectBrokerId)->select('id')))
                        ->orWhere(fn (Builder $x) => $x->where('subject_type', class_basename(BrokerConsentDispute::class))
                            ->whereIn('subject_id', BrokerConsentDispute::query()->where('broker_id', $alsoSubjectBrokerId)->select('id')))))))
            ->when($group !== null, fn (Builder $q) => $q->whereIn('action', $group))
            ->when($from !== null, fn (Builder $q) => $q->where('created_at', '>=', $from))
            ->when($to !== null, fn (Builder $q) => $q->where('created_at', '<=', $to));
    }

    /** Human label for an audit action (unknown actions fall back to a neutral one). */
    public static function label(string $action): string
    {
        return match ($action) {
            'broker.profile_created' => __('Added a profile'),
            'broker.profile_submitted' => __('Sent a profile for review'),
            'broker.profile_deactivate' => __('Deactivated a profile'),
            'broker.profile_activate' => __('Activated a profile'),
            'broker.profile_delete' => __('Deleted a profile'),
            'broker.profile_restore' => __('Restored a profile'),
            'broker.profile_client_phone_changed' => __('Changed a client’s mobile'),
            'broker.profiles_assigned' => __('Assigned profiles'),
            'broker.interest_accepted' => __('Accepted an interest for a client'),
            'broker.interest_declined' => __('Declined an interest for a client'),
            'broker.interest_sent' => __('Sent an interest for a client'),
            'broker.profile_exported' => __('Exported a profile'),
            'broker.follow_up_completed' => __('Completed a follow-up'),
            'broker.staff_invited' => __('Invited a staff member'),
            'broker.staff_invite_cancelled' => __('Cancelled an invitation'),
            'broker.staff_joined' => __('Joined the bureau'),
            'broker.staff_permissions_changed' => __('Changed a staff member’s permissions'),
            'broker.staff_deactivated' => __('Deactivated a staff member'),
            'broker.kyc_submitted' => __('Submitted KYC'),
            'broker.device_verified' => __('Confirmed a new device'),
            default => __('Other activity'),
        };
    }

    /** A Y-m-d date typed in a filter, or null when absent / not a real date. */
    public static function date(?string $value, string $tz): ?CarbonImmutable
    {
        if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $tz);

        return $date?->format('Y-m-d') === $value ? $date : null;
    }
}
