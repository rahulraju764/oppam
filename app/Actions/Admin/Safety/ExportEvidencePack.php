<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Data\Chat\ModeratedMessageData;
use App\Data\Safety\CaseEvidenceData;
use App\Models\AbuseCase;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\Profile;
use App\Models\Report;
use App\Models\SafetyAction;
use App\Queries\Safety\CaseEvidence;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use RuntimeException;
use ZipArchive;

/**
 * The legal-hold evidence pack of an A10 case (PRD A10 "legal hold (super admin) with evidence-pack
 * export", decisions 2026-10-23): a zip of JSON files — the case, its reports (reasons, details,
 * reporter codes), the chat evidence windows (CaseEvidence, never whole conversations), the safety
 * actions and the audit rows about the case. Super admins only, and only while the member is under
 * legal hold; audited. Built in a temporary file the controller streams and deletes — never stored.
 */
final class ExportEvidencePack
{
    public function __construct(
        private readonly CaseEvidence $evidence,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return string the temporary zip's path (the caller deletes it after sending)
     *
     * @throws AuthorizationException
     */
    public function handle(AdminUser $admin, AbuseCase $case): string
    {
        $profile = Profile::withTrashed()->find($case->reported_profile_id);
        if (! $admin->isSuperAdmin() || $profile?->legal_hold_at === null) {
            throw new AuthorizationException(__('The evidence pack is for super admins, for a member under legal hold.'));
        }

        $zone = (string) config('oppam.display_timezone');
        $files = [
            'case.json' => [
                'code' => $case->code,
                'member' => $profile->code,
                'status' => $case->status->value,
                'priority' => $case->priority->value,
                'outcome' => $case->outcome?->value,
                'outcome_note' => $case->outcome_note,
                'opened_at' => $case->created_at?->toIso8601String(),
                'decided_at' => $case->decided_at?->toIso8601String(),
                'legal_hold_at' => $profile->legal_hold_at->toIso8601String(),
                'exported_at' => now()->toIso8601String(),
                'exported_by' => $admin->email,
            ],
            'reports.json' => Report::query()->where('abuse_case_id', $case->id)->with('reporter:id,code')->orderBy('created_at')->get()
                ->map(fn (Report $r): array => [
                    'id' => $r->id, 'source' => $r->source->value, 'reason' => $r->reason->value, 'details' => $r->details,
                    'reporter' => $r->reporter?->code, 'status' => $r->status->value, 'created_at' => $r->created_at?->toIso8601String(),
                ])->all(),
            'chat-evidence.json' => array_map(fn (CaseEvidenceData $e): array => [
                'report_id' => $e->reportId,
                'reporter' => $e->reporterCode,
                'messages' => array_map(fn (ModeratedMessageData $m): array => [
                    'id' => $m->id, 'sender' => $m->senderLabel, 'text' => $m->text, 'at_ist' => $m->at->setTimezone($zone)->format('Y-m-d H:i:s'), 'reported' => $m->isFlagged,
                ], $e->messages),
            ], $this->evidence->for($case)),
            'safety-actions.json' => SafetyAction::query()->where('profile_id', $case->reported_profile_id)->orderBy('created_at')->get()
                ->map(fn (SafetyAction $a): array => ['outcome' => $a->outcome->value, 'note' => $a->note, 'details' => $a->details, 'at' => $a->created_at?->toIso8601String()])->all(),
            'audit.json' => AuditLog::query()->where('subject_type', 'AbuseCase')->where('subject_id', $case->id)->orderBy('created_at')->get()
                ->map(fn (AuditLog $l): array => ['action' => $l->action, 'actor' => $l->actor_label, 'reason' => $l->reason, 'at' => $l->created_at->toIso8601String()])->all(),
        ];

        $path = tempnam(sys_get_temp_dir(), 'evidence-');
        $zip = new ZipArchive;
        if ($path === false || $zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the evidence pack.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, (string) json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $zip->close();

        $this->audit->record('safety.evidence_pack.exported', $case, after: ['files' => array_keys($files)],
            reason: __('Legal hold'), actor: $admin, subjectLabel: $profile->code);

        return $path;
    }
}
