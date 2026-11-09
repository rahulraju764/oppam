<?php

declare(strict_types=1);

use App\Contracts\MailBounceVerifier;
use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use App\Services\Mail\MailgunBounceVerifier;
use App\Services\Mail\SesSnsVerifier;

/*
| P8.3a — PRD §13 webhooks for the A14 delivery log: MSG91 delivery reports (shared secret — MSG91
| doesn't sign) and mail bounces (provider signature; Mailgun now, SES fails closed). Verified
| before anything changes; idempotent; an unknown id is ignored.
*/

const DLR_SECRET = 'dlr-secret-for-tests';
const MG_KEY = 'mailgun-signing-key-for-tests';

beforeEach(function (): void {
    config(['oppam.comms.msg91_dlr_secret' => DLR_SECRET]);
});

function dlrPost(array $body, ?string $secret = DLR_SECRET): Illuminate\Testing\TestResponse
{
    $url = '/webhooks/msg91/dlr'.($secret !== null ? '?secret='.$secret : '');

    return test()->call('POST', memberUrl($url), [], [], [], ['CONTENT_TYPE' => 'application/json'], (string) json_encode($body));
}

/** A signed Mailgun "failed" event for this message id. */
function mailgunEvent(string $messageId, string $severity = 'permanent', ?int $timestamp = null, string $token = 'tok-1', string $key = MG_KEY): array
{
    $timestamp ??= time();

    return [
        'signature' => ['timestamp' => (string) $timestamp, 'token' => $token, 'signature' => hash_hmac('sha256', $timestamp.$token, $key)],
        'event-data' => ['event' => 'failed', 'severity' => $severity, 'message' => ['headers' => ['message-id' => $messageId]]],
    ];
}

it('§13 MSG91 DLR: the right secret marks the SMS DELIVERED or FAILED; a replay changes nothing', function (): void {
    $ok = OutboundMessage::factory()->create(['channel' => CommsChannel::Sms, 'provider_id' => 'req-1']);
    $bad = OutboundMessage::factory()->create(['channel' => CommsChannel::Sms, 'provider_id' => 'req-2']);

    dlrPost([['requestId' => 'req-1', 'report' => [['status' => '1']]], ['requestId' => 'req-2', 'report' => [['status' => '2']]]])
        ->assertOk()->assertJson(['updated' => 2]);

    expect($ok->refresh()->status)->toBe(OutboundStatus::Delivered)->and($ok->delivered_at)->not->toBeNull()
        ->and($bad->refresh()->status)->toBe(OutboundStatus::Failed);

    dlrPost([['requestId' => 'req-1', 'report' => [['status' => '2']]]])->assertJson(['updated' => 0]);   // no going back
    expect($ok->refresh()->status)->toBe(OutboundStatus::Delivered);
});

it('§13 MSG91 DLR: a wrong or missing secret (or none configured) → 403, nothing changes', function (?string $given, ?string $configured): void {
    config(['oppam.comms.msg91_dlr_secret' => $configured]);
    $row = OutboundMessage::factory()->create(['channel' => CommsChannel::Sms, 'provider_id' => 'req-9']);

    dlrPost([['requestId' => 'req-9', 'report' => [['status' => '1']]]], $given)->assertForbidden();
    expect($row->refresh()->status)->toBe(OutboundStatus::Sent);
})->with([
    'wrong secret' => ['nope', DLR_SECRET],
    'no secret' => [null, DLR_SECRET],
    'none configured' => ['', ''],
]);

it('§13 MSG91 DLR: an unknown request id, an email row with the same id, or junk is ignored', function (): void {
    $mail = OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'provider_id' => 'req-5']);

    dlrPost([['requestId' => 'unknown', 'report' => [['status' => '1']]], ['requestId' => 'req-5', 'report' => [['status' => '1']]], 'junk'])
        ->assertOk()->assertJson(['updated' => 0]);
    expect($mail->refresh()->status)->toBe(OutboundStatus::Sent);
});

it('§13 mail bounce (Mailgun): a valid signature marks the email BOUNCED', function (): void {
    app()->instance(MailBounceVerifier::class, new MailgunBounceVerifier(MG_KEY));
    $row = OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'provider_id' => 'abc@mail.oppam.test']);

    $this->postJson(memberUrl('/webhooks/mail/bounce'), mailgunEvent('<abc@mail.oppam.test>'))->assertOk()->assertJson(['updated' => 1]);

    expect($row->refresh()->status)->toBe(OutboundStatus::Bounced)->and($row->bounced_at)->not->toBeNull()
        ->and($row->error_message)->toBe('Hard bounce');
});

it('§13 mail bounce: bad signature, old timestamp, replayed token or no key → 403, nothing changes', function (string $case): void {
    app()->instance(MailBounceVerifier::class, new MailgunBounceVerifier($case === 'no key' ? '' : MG_KEY));
    $row = OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'provider_id' => 'abc@mail.oppam.test']);

    $event = match ($case) {
        'bad signature' => mailgunEvent('abc@mail.oppam.test', key: 'wrong-key'),
        'old timestamp' => mailgunEvent('abc@mail.oppam.test', timestamp: time() - 3600),
        default => mailgunEvent('abc@mail.oppam.test'),
    };
    if ($case === 'replayed token') {
        $this->postJson(memberUrl('/webhooks/mail/bounce'), $event)->assertOk();
        $row->refresh()->forceFill(['status' => OutboundStatus::Sent, 'bounced_at' => null])->save();
    }

    $this->postJson(memberUrl('/webhooks/mail/bounce'), $event)->assertForbidden();
    expect($row->refresh()->status)->toBe(OutboundStatus::Sent);
})->with(['bad signature', 'old timestamp', 'replayed token', 'no key']);

it('§13 mail bounce: the SES driver refuses everything until it is built (fails closed)', function (): void {
    app()->instance(MailBounceVerifier::class, new SesSnsVerifier);

    $this->postJson(memberUrl('/webhooks/mail/bounce'), mailgunEvent('abc@mail.oppam.test'))->assertForbidden();
});
