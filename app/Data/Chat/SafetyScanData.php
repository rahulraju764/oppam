<?php

declare(strict_types=1);

namespace App\Data\Chat;

use App\Enums\SafetyRule;

/**
 * What ContentSafety made of a message: the text to store and show (matches of MASK rules hidden),
 * why it is flagged (one finding per A13 queue reason), the normalised text's hash for the
 * repeated-message check, and — when a BLOCK rule matched — that rule: the message must not be sent.
 * `hits` lists every rule that matched (the A13 dry-run tester shows them).
 */
final readonly class SafetyScanData
{
    /**
     * @param  list<SafetyFindingData>  $findings
     * @param  list<SafetyHitData>  $hits
     */
    public function __construct(
        public string $body,
        public array $findings,
        public ?string $bodyHash,
        public bool $masked,
        public ?SafetyRule $blockedBy = null,
        public array $hits = [],
    ) {}

    public function flagged(): bool
    {
        return $this->findings !== [];
    }

    public function blocked(): bool
    {
        return $this->blockedBy !== null;
    }
}
