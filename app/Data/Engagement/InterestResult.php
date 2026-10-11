<?php

declare(strict_types=1);

namespace App\Data\Engagement;

/**
 * What SendInterest left behind: where the member now stands with the profile
 * (Connections::SENT or ::CONNECTED) and whether this call wrote anything. `connected` is true
 * when the other member had already sent a pending interest, which this send accepted.
 */
final readonly class InterestResult
{
    public function __construct(
        public string $state,
        public bool $changed,
        public bool $connected = false,
    ) {}
}
