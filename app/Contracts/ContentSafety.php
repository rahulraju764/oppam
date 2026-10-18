<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Chat\SafetyScanData;

/**
 * Scans a chat message as it is sent (PRD M07 send flow, A13): masks contact details and links in
 * a thread's first messages, and says why the message should be in the A13 flagged queue
 * (contact details, links, money / UPI / bank requests, abusive words, the same text sent into many
 * threads). Never drops a message: the worst outcome is a masked, flagged message. Bound to
 * RuleBasedContentSafety; an outside moderation service could replace it.
 */
interface ContentSafety
{
    /**
     * @param  string  $body  the trimmed text the member typed
     * @param  bool  $early  the message is among the thread's first `chat.safety_first_messages`
     */
    public function scan(string $body, string $senderProfileId, string $conversationId, bool $early): SafetyScanData;
}
