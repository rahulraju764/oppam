<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Domain\Safety\AbuseCases;
use App\Enums\CasePriority;
use App\Exceptions\Safety\CaseUnavailable;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Hand triage of an OPEN A10 case (`safety.reports.act`): take it (assign to me) or change its
 * priority — the SLA due time is counted again from when the case opened. A typed reason (5 – 500)
 * for a priority change; both audited. The queue updates live.
 */
final class TriageAbuseCase
{
    public function __construct(
        private readonly AbuseCases $cases,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws AuthorizationException|CaseUnavailable */
    public function assignToMe(AdminUser $admin, AbuseCase $case): void
    {
        Gate::forUser($admin)->authorize('safety.reports.act');

        DB::transaction(function () use ($admin, $case): void {
            $locked = $this->lockOpen($case);
            $before = $locked->assigned_admin_id;
            $locked->assigned_admin_id = $admin->id;
            $locked->save();

            $this->audit->record('safety.case.assigned', $locked, ['assigned_admin_id' => $before], ['assigned_admin_id' => $admin->id],
                actor: $admin, subjectLabel: $this->code($locked));
            $this->cases->announceCount();
        });
    }

    /** @throws AuthorizationException|ValidationException|CaseUnavailable */
    public function changePriority(AdminUser $admin, AbuseCase $case, CasePriority $priority, string $reason): void
    {
        Gate::forUser($admin)->authorize('safety.reports.act');
        $reason = trim($reason);
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 500 characters (it is recorded in the audit log).')]);
        }

        DB::transaction(function () use ($admin, $case, $priority, $reason): void {
            $locked = $this->lockOpen($case);
            $before = $locked->priority;
            $locked->priority = $priority;
            $locked->sla_due_at = ($locked->created_at ?? now())->copy()->addHours($priority->slaHours());
            $locked->save();

            $this->audit->record('safety.case.priority_changed', $locked, ['priority' => $before->value], ['priority' => $priority->value],
                reason: $reason, actor: $admin, subjectLabel: $this->code($locked));
            $this->cases->announceCount();
        });
    }

    private function lockOpen(AbuseCase $case): AbuseCase
    {
        $locked = AbuseCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
        if (! $locked->isOpen()) {
            throw CaseUnavailable::decided();
        }

        return $locked;
    }

    private function code(AbuseCase $case): ?string
    {
        $code = Profile::withTrashed()->whereKey($case->reported_profile_id)->value('code');

        return is_string($code) ? $code : null;
    }
}
