<?php

declare(strict_types=1);

namespace App\Data\Comms;

/** One bounce from a mail provider's webhook: the message id we logged when we sent it. */
final readonly class BounceReport
{
    public function __construct(
        public string $providerMessageId,
        public bool $permanent,
    ) {}
}
