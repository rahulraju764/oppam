<?php

declare(strict_types=1);

namespace App\Data\Chat;

use App\Enums\MessageFlagRule;

/**
 * One reason ContentSafety found to flag a message, with the excerpt a moderator may reveal: the
 * masked / matched text only (a phone number, a link, the abusive word), never the whole message.
 */
final readonly class SafetyFindingData
{
    public function __construct(
        public MessageFlagRule $rule,
        public ?string $excerpt = null,
    ) {}
}
