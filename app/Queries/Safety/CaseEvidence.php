<?php

declare(strict_types=1);

namespace App\Queries\Safety;

use App\Data\Chat\ModeratedMessageData;
use App\Data\Safety\CaseEvidenceData;
use App\Enums\ReportSource;
use App\Enums\SettingKey;
use App\Models\AbuseCase;
use App\Models\Message;
use App\Models\Report;
use App\Queries\Chat\ModeratedMessages;
use App\Support\Facades\Settings;
use Illuminate\Support\Collection;

/**
 * The A10 evidence window (PRD A10 "conversation evidence behind an audited reveal — only messages
 * around the reported one"): for every chat report in the case, the messages the member selected
 * and at most `safety.evidence_context_messages` either side of each, in the same thread, by id.
 * Read only; RevealCaseEvidence is the audited way in. Photos are never shown here (A13 has the
 * audited image route) — they read "Photo".
 */
final class CaseEvidence
{
    public function __construct(private readonly ModeratedMessages $moderated) {}

    /** @return list<CaseEvidenceData> */
    public function for(AbuseCase $case): array
    {
        $context = Settings::int(SettingKey::SafetyEvidenceContextMessages);
        $reports = Report::query()->where('abuse_case_id', $case->id)->where('source', ReportSource::Chat->value)
            ->with(['messages:id,conversation_id', 'reporter:id,code'])->orderBy('created_at')->get();

        $evidence = [];
        foreach ($reports as $report) {
            $reportedIds = $report->messages->modelKeys();
            $window = collect();
            foreach ($report->messages as $message) {
                $window = $window->concat($this->around($message, $context));
            }

            $rows = $this->moderated->of($window->unique('id')->sortBy('id')->values());
            $evidence[] = new CaseEvidenceData(
                reportId: $report->id,
                reporterCode: (string) ($report->reporter->code ?? '—'),
                reasonLabel: $report->reason->label(),
                messages: array_map(fn (ModeratedMessageData $row): ModeratedMessageData => new ModeratedMessageData(
                    $row->id, $row->senderLabel, $row->text, $row->at, in_array($row->id, $reportedIds, true), false, $row->gone,
                ), $rows),
            );
        }

        return $evidence;
    }

    /** @return Collection<int, Message> */
    private function around(Message $message, int $context): Collection
    {
        $before = Message::query()->where('conversation_id', $message->conversation_id)->where('id', '<', $message->id)
            ->orderByDesc('id')->limit($context)->get(ModeratedMessages::COLUMNS);
        $after = Message::query()->where('conversation_id', $message->conversation_id)->where('id', '>', $message->id)
            ->orderBy('id')->limit($context)->get(ModeratedMessages::COLUMNS);

        return $before->concat(Message::query()->whereKey($message->id)->get(ModeratedMessages::COLUMNS))->concat($after);
    }
}
