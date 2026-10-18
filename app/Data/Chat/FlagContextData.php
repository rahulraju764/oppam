<?php

declare(strict_types=1);

namespace App\Data\Chat;

/**
 * What an A13 reveal shows (PRD A13 "only the flagged message with ±3 messages of context"): the
 * flagged message, up to three messages before and after it in the same thread — nothing else —
 * and the flag's decrypted excerpt (what ContentSafety masked or matched).
 */
final readonly class FlagContextData
{
    /**
     * @param  list<ModeratedMessageData>  $before  oldest first
     * @param  list<ModeratedMessageData>  $after  oldest first
     */
    public function __construct(
        public ModeratedMessageData $flagged,
        public array $before,
        public array $after,
        public ?string $excerpt,
    ) {}
}
