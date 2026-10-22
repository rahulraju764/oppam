<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

/** The gateway could not be reached or refused the call. The message never reaches a member. */
final class PaymentGatewayUnavailable extends RuntimeException
{
    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self('Payment gateway unavailable: '.$reason, 0, $previous);
    }
}
