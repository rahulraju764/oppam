<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Contracts\MailBounceVerifier;
use App\Data\Comms\BounceReport;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mailgun "failed" / "permanent_fail" webhooks (A14, P8.3a). Mailgun signs every event:
 * HMAC-SHA256(timestamp . token) with the webhook signing key. Refused when the key isn't set,
 * the signature doesn't match, the timestamp is older than `oppam.comms.bounce_max_age_seconds`,
 * or the token was seen before (replay guard: webhook_replay_tokens).
 */
final class MailgunBounceVerifier implements MailBounceVerifier
{
    public function __construct(private readonly string $signingKey) {}

    public function verify(Request $request): ?array
    {
        $signature = $request->input('signature');
        if ($this->signingKey === '' || ! is_array($signature)) {
            return null;
        }

        $timestamp = (string) ($signature['timestamp'] ?? '');
        $token = (string) ($signature['token'] ?? '');
        $given = (string) ($signature['signature'] ?? '');

        if ($timestamp === '' || $token === '' || $given === '' || ! ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > (int) config('oppam.comms.bounce_max_age_seconds')
            || ! hash_equals(hash_hmac('sha256', $timestamp.$token, $this->signingKey), $given)) {
            return null;
        }

        try {
            DB::table('webhook_replay_tokens')->insert(['provider' => 'mailgun', 'token_hash' => hash('sha256', $token), 'created_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            return null;   // the same signed event twice: a replay
        }

        $event = $request->input('event-data');
        if (! is_array($event) || ! in_array($event['event'] ?? null, ['failed'], true)) {
            return [];
        }

        $messageId = (string) ($event['message']['headers']['message-id'] ?? '');

        return $messageId === '' ? [] : [new BounceReport(trim($messageId, '<>'), ($event['severity'] ?? '') === 'permanent')];
    }
}
