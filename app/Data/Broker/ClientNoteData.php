<?php

declare(strict_types=1);

namespace App\Data\Broker;

/**
 * A client note / call / meeting as typed in the bureau's notes form (§11A B.6). Validated by
 * AddClientNote; `followUpOn` is an IST date (Y-m-d) or null.
 */
final readonly class ClientNoteData
{
    public function __construct(
        public string $type,
        public ?string $outcome,
        public string $body,
        public ?string $followUpOn,
    ) {}

    /** @return array{type: string, outcome: string|null, body: string, follow_up_on: string|null} */
    public function toArray(): array
    {
        return ['type' => $this->type, 'outcome' => $this->outcome, 'body' => $this->body, 'follow_up_on' => $this->followUpOn];
    }
}
