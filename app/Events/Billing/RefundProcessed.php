<?php

declare(strict_types=1);

namespace App\Events\Billing;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A refund reached PROCESSED (the money went back). Internal only — not broadcast. Listened to by
 * the broker ledger (VoidReferralOnRefund, R-M13-5). Dispatched by A06's refund-processed path
 * (P5.3) once that path exists.
 */
final class RefundProcessed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly string $refundId) {}
}
