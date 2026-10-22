<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/** A payment webhook whose signature doesn't verify or whose body isn't an event: nothing is written. */
final class InvalidWebhook extends RuntimeException
{
    public static function signature(): self
    {
        return new self('Webhook signature mismatch.');
    }

    public static function body(): self
    {
        return new self('Webhook body is not a payment event.');
    }
}
