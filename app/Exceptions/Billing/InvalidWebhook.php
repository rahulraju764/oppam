<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use App\Enums\WebhookOutcome;
use RuntimeException;

/** A payment webhook whose signature doesn't verify or whose body isn't an event: nothing is written. */
final class InvalidWebhook extends RuntimeException
{
    private const SIGNATURE = 1;

    private const BODY = 2;

    public static function signature(): self
    {
        return new self('Webhook signature mismatch.', self::SIGNATURE);
    }

    public static function body(): self
    {
        return new self('Webhook body is not a payment event.', self::BODY);
    }

    /** How the A12 webhook receipts count this refusal. */
    public function outcome(): WebhookOutcome
    {
        return $this->getCode() === self::BODY ? WebhookOutcome::RejectedBody : WebhookOutcome::RejectedSignature;
    }
}
