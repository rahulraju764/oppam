<?php

declare(strict_types=1);

namespace App\Data\Chat;

/**
 * What ContentSafety made of a message: the text to store and show (contact details / links
 * masked when the thread is new), why it is flagged (one finding per rule), and the normalised
 * text's hash for the repeated-message check.
 */
final readonly class SafetyScanData
{
    /** @param  list<SafetyFindingData>  $findings */
    public function __construct(
        public string $body,
        public array $findings,
        public ?string $bodyHash,
        public bool $masked,
    ) {}

    public function flagged(): bool
    {
        return $this->findings !== [];
    }
}
