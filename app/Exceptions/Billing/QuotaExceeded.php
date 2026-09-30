<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\Entitlement;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * A plan limit is used up (PRD §7.3). Livewire components turn this into the "Upgrade" prompt;
 * resetsAt tells the member when the counter starts again (null = only by upgrading).
 */
final class QuotaExceeded extends RuntimeException
{
    public function __construct(
        public readonly Entitlement $entitlement,
        public readonly int $limit,
        public readonly ?CarbonImmutable $resetsAt,
    ) {
        parent::__construct(sprintf('Quota used up for %s (limit %d).', $entitlement->value, $limit));
    }
}
