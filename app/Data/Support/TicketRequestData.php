<?php

declare(strict_types=1);

namespace App\Data\Support;

/**
 * What a member or visitor sends to open a support ticket (contact form / in-app help). Raw input:
 * OpenTicket validates every field; for a signed-in member it takes the name and email from the
 * account when they are left empty.
 */
final readonly class TicketRequestData
{
    public function __construct(
        public string $name,
        public ?string $email,
        public ?string $phone,
        public string $category,
        public string $subject,
        public string $message,
    ) {}
}
