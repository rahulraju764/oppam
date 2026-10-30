<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\BrokerPayoutItem;

/** Renders one bureau's payout statement as PDF bytes (§11A B.10). Implemented with dompdf. */
interface PayoutStatementRenderer
{
    public function render(BrokerPayoutItem $item): string;
}
