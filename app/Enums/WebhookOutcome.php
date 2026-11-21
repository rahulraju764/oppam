<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A12 system health: what happened to one inbound webhook request (webhook_receipts.outcome).
 * REJECTED_SIGNATURE covers a wrong signature / shared secret and a replayed mail token (the
 * verifier can't tell them apart); REJECTED_BODY a verified request that isn't an event.
 */
enum WebhookOutcome: string
{
    case Accepted = 'ACCEPTED';
    case RejectedSignature = 'REJECTED_SIGNATURE';
    case RejectedBody = 'REJECTED_BODY';
    case Error = 'ERROR';

    /** Request attribute a webhook controller sets to say why it refused (RecordWebhookReceipt reads it). */
    public const REQUEST_ATTRIBUTE = 'webhook.rejection';

    public function label(): string
    {
        return match ($this) {
            self::Accepted => __('Accepted'),
            self::RejectedSignature => __('Signature rejected'),
            self::RejectedBody => __('Body rejected'),
            self::Error => __('Error'),
        };
    }

    /** The outcome of a finished request: the controller's own tag first, then the status code. */
    public static function fromResponse(int $status, ?self $tagged): self
    {
        return match (true) {
            $tagged !== null => $tagged,
            $status >= 500 => self::Error,
            $status === 401, $status === 403 => self::RejectedSignature,
            $status >= 400 => self::RejectedBody,
            default => self::Accepted,
        };
    }
}
