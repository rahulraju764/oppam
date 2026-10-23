<?php

declare(strict_types=1);

namespace App\Data\Safety;

use App\Data\Chat\ModeratedMessageData;

/**
 * The chat evidence of one report in an A10 case, after the audited reveal (PRD A10 "only messages
 * around the reported one"): each message the member selected, with at most
 * `safety.evidence_context_messages` messages either side in the same thread — never the rest.
 */
final readonly class CaseEvidenceData
{
    /** @param  list<ModeratedMessageData>  $messages  oldest first; the reported ones have isFlagged */
    public function __construct(
        public string $reportId,
        public string $reporterCode,
        public string $reasonLabel,
        public array $messages,
    ) {}
}
