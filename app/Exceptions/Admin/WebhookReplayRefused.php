<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** An A12 payment-webhook replay that can't go ahead (shown to the admin as is; codes for tests and the audit row). */
final class WebhookReplayRefused extends RuntimeException
{
    public const ALREADY_PROCESSED = 1;

    public const NOT_CONFIRMED = 2;

    public const GATEWAY_UNAVAILABLE = 3;

    public const IN_PROGRESS = 4;

    public const FAILED_AGAIN = 5;

    public const TOO_MANY = 6;

    public static function alreadyProcessed(): self
    {
        return new self(__('This webhook has already been processed — there is nothing to replay.'), self::ALREADY_PROCESSED);
    }

    public static function notConfirmed(): self
    {
        return new self(__('Razorpay doesn’t confirm this payment (it isn’t listed for the order with that status), so it wasn’t replayed.'), self::NOT_CONFIRMED);
    }

    public static function gatewayUnavailable(): self
    {
        return new self(__('Razorpay didn’t answer, so the payment couldn’t be confirmed. Try again shortly.'), self::GATEWAY_UNAVAILABLE);
    }

    public static function inProgress(): self
    {
        return new self(__('This webhook is being replayed right now. Wait a moment and refresh.'), self::IN_PROGRESS);
    }

    public static function failedAgain(string $errorClass): self
    {
        return new self(__('Processing failed again (:error). The attempt is counted on the webhook and the error was reported.', ['error' => class_basename($errorClass)]), self::FAILED_AGAIN);
    }

    public static function tooMany(): self
    {
        return new self(__('You have replayed several webhooks in the last hour. Try again later.'), self::TOO_MANY);
    }
}
