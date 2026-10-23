<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Data\Safety\CaseEvidenceData;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Queries\Safety\CaseEvidence;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Reveal a case's chat evidence to a reviewer (PRD A10 "conversation evidence behind an audited
 * 'reveal evidence' click (only messages around the reported one)"): CaseEvidence's windows, never
 * the rest of a conversation. Needs `safety.reports.view`; every reveal writes an audit row naming
 * the case and how many messages were shown.
 */
final class RevealCaseEvidence
{
    public function __construct(
        private readonly CaseEvidence $evidence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<CaseEvidenceData>
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, AbuseCase $case): array
    {
        Gate::forUser($admin)->authorize('safety.reports.view');

        $evidence = $this->evidence->for($case);

        $this->audit->record('safety.evidence.revealed', $case, after: [
            'reports' => count($evidence),
            'messages_shown' => array_sum(array_map(fn (CaseEvidenceData $e): int => count($e->messages), $evidence)),
        ], reason: __('Abuse case :code', ['code' => $case->code]), actor: $admin,
            subjectLabel: Profile::withTrashed()->whereKey($case->reported_profile_id)->value('code'));

        return $evidence;
    }
}
