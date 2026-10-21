<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Chat\SafetyRuleData;
use App\Data\Chat\SafetyScanData;

/**
 * Scans a chat message as it is sent (PRD M07 send flow, A13) against the A13 rules (ChatSafetyRules:
 * defaults + the rules editor's overrides): MASK rules hide what they match from the recipient,
 * FLAG rules put the message in the A13 flagged queue, a BLOCK rule means the message must not be
 * sent (SendMessage refuses it — decisions 2026-10-18). Bound to RuleBasedContentSafety; an outside
 * moderation service could replace it.
 */
interface ContentSafety
{
    /**
     * @param  string  $body  the trimmed text the member typed
     * @param  bool  $early  the message is among the thread's first `chat.safety_first_messages`
     */
    public function scan(string $body, string $senderProfileId, string $conversationId, bool $early): SafetyScanData;

    /**
     * The A13 dry-run tester: the same rules (one of them optionally replaced by a draft being edited)
     * on sample text — nothing is read from or written to the database, so the repeated-message rule
     * is not evaluated.
     */
    public function dryRun(string $body, bool $early, ?SafetyRuleData $draft = null): SafetyScanData;
}
