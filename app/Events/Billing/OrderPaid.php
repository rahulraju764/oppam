<?php

declare(strict_types=1);

namespace App\Events\Billing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An order was paid and its plan activated (F04 "OrderPaid event → broker commission EARNED if
 * referred / managed (M13)"). Internal only — not broadcast. The broker commission listener
 * arrives with P7.8.
 */
final class OrderPaid implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $orderId) {}
}
