<?php

declare(strict_types=1);

namespace App\Exceptions\Sms;

use RuntimeException;
use Throwable;

/**
 * The SMS provider refused or could not be reached. getMessage() is safe to show to members;
 * $reason is for logs and MUST be fixed text (no code, no full number, no provider/HTTP-client
 * exception text — Guzzle's messages include the request URI).
 */
final class SmsDeliveryFailed extends RuntimeException
{
    public function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(__('We could not send the SMS just now. Please try again in a minute.'), 0, $previous);
    }

    public static function because(string $reason, ?Throwable $previous = null): self
    {
        return new self($reason, $previous);
    }
}
