<?php

declare(strict_types=1);

namespace App\Data\Comms;

use App\Enums\MailEventKind;

/**
 * One signed event from a mail provider's webhook, naming the message id we logged when we sent it
 * (A14 delivery log): a bounce (P8.3a), or a delivery / open (P8.3b1 campaign stats).
 */
final readonly class MailEventReport
{
    public function __construct(
        public string $providerMessageId,
        public MailEventKind $kind = MailEventKind::Bounced,
        public bool $permanent = false,
    ) {}
}
