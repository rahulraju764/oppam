<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\ResendFailedMessage;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Enums\CommsChannel;
use App\Enums\OtpPurpose;
use App\Enums\OutboundStatus;
use App\Enums\UserStatus;
use App\Exceptions\Admin\CommsRefused;
use App\Jobs\Comms\PruneOutboundMessages;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Auth\PasswordResetLink;
use App\Notifications\Profile\ProfileApproved;
use App\Notifications\Support\SmsChannel;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Facades\Notification;

/*
| P8.3a — A14 delivery log: "every outbound email / SMS with status and provider id; resend for
| transactional failures". Never the message text; recipients masked; token mails never resendable.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->sms = fakeSms();
});

function dlMember(string $phone = '+919811600001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'anjali.dl@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

it('A14: a member email and SMS are each logged — masked recipient, event, provider id, no message text', function (): void {
    $member = dlMember();

    app(MailChannel::class)->send($member, new ProfileApproved($member->profile->code));
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));

    $mail = OutboundMessage::query()->where('channel', CommsChannel::Email->value)->sole();
    $sms = OutboundMessage::query()->where('channel', CommsChannel::Sms->value)->sole();

    expect($mail->recipient_masked)->toBe('a•••@example.com')
        ->and($mail->event)->toBe('profile_approved')
        ->and($mail->status)->toBe(OutboundStatus::Sent)
        ->and($mail->provider_id)->not->toBeNull()
        ->and($mail->user_id)->toBe($member->id)
        ->and($sms->recipient_masked)->not->toContain('9811600001')
        ->and($sms->provider_id)->toBe('fake-1');

    // Nothing of the message itself is stored (only the encrypted, resendable notification).
    $raw = DB::table('outbound_messages')->get()->toJson();
    expect($raw)->not->toContain('anjali.dl@example.com')->not->toContain('9811600001')->not->toContain('visible to other members');
});

it('A14: a failed SMS is logged as FAILED (the send still never breaks the notification)', function (): void {
    $member = dlMember();
    $this->sms->failing = true;
    Illuminate\Support\Facades\Exceptions::fake();

    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));

    $row = OutboundMessage::query()->sole();
    expect($row->status)->toBe(OutboundStatus::Failed)->and($row->failed_at)->not->toBeNull()
        ->and($row->isResendable())->toBeTrue();
});

it('A14: OTP and password-reset messages are logged without anything resendable (tokens never stored)', function (): void {
    $member = dlMember();
    app(App\Actions\Auth\SendOtp::class)->handle(PhoneNumber::fromE164($member->phone), OtpPurpose::Login, '10.9.9.9', $member);
    app()->terminate();   // the OTP SMS goes out after the response (defer)
    $member->notifyNow(new PasswordResetLink('https://oppam.test/reset-password/a-secret-reset-token-123', 60));

    $otp = OutboundMessage::query()->where('event', 'otp_login')->sole();
    $reset = OutboundMessage::query()->where('notification_type', PasswordResetLink::class)->sole();

    expect($otp->payload)->toBeNull()->and($reset->payload)->toBeNull()
        ->and($otp->isResendable())->toBeFalse()
        ->and(DB::table('outbound_messages')->get()->toJson())->not->toContain('a-secret-reset-token-123');
});

it('A14 resend: a failed member notification goes out again on that channel, as a new row linked to the old one', function (): void {
    $member = dlMember();
    $this->sms->failing = true;
    Illuminate\Support\Facades\Exceptions::fake();
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    $failed = OutboundMessage::query()->sole();

    $this->sms->failing = false;
    app(ResendFailedMessage::class)->handle(adminWithRole(), $failed);

    $resent = OutboundMessage::query()->where('resent_from_id', $failed->id)->sole();
    expect($resent->status)->toBe(OutboundStatus::Sent)->and($this->sms->notices)->toHaveCount(1)
        ->and(App\Models\AuditLog::query()->where('action', 'comms.message_resent')->count())->toBe(1);
});

it('A14 resend is refused: a sent row, a token mail, a member gone or who switched the channel off', function (string $case): void {
    $member = dlMember();
    Illuminate\Support\Facades\Exceptions::fake();
    $this->sms->failing = true;
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    $row = OutboundMessage::query()->sole();

    match ($case) {
        'already sent' => $row->forceFill(['status' => OutboundStatus::Sent])->save(),
        'token mail' => $row->forceFill(['payload' => null])->save(),
        'member suspended' => $member->forceFill(['status' => UserStatus::Suspended])->save(),
        // profile_approved is mandatory (can't be switched off): the router still drops SMS without a verified phone.
        'can no longer get SMS' => $member->forceFill(['phone_verified_at' => null])->save(),
    };
    $this->sms->failing = false;

    expect(fn () => app(ResendFailedMessage::class)->handle(adminWithRole(), $row->refresh()))->toThrow(CommsRefused::class);
    expect($this->sms->notices)->toBe([]);
})->with(['already sent', 'token mail', 'member suspended', 'can no longer get SMS']);

it('A14 resend: each failed row is sent again once only (a double click sends one)', function (): void {
    $member = dlMember();
    $this->sms->failing = true;
    Illuminate\Support\Facades\Exceptions::fake();
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    $failed = OutboundMessage::query()->sole();
    $this->sms->failing = false;

    app(ResendFailedMessage::class)->handle(adminWithRole(), $failed);
    expect(fn () => app(ResendFailedMessage::class)->handle(adminWithRole(), $failed->refresh()))->toThrow(CommsRefused::class);
    expect($this->sms->notices)->toHaveCount(1);
});

it('R-M08-1 / rule 4: a resend about a member the recipient has since blocked (either way) is refused, nothing sent', function (string $who): void {
    $member = dlMember();
    $other = groom('+919811600009');
    $this->sms->failing = true;
    Illuminate\Support\Facades\Exceptions::fake();
    app(SmsChannel::class)->send($member, new App\Notifications\Engagement\InterestNotification(App\Enums\NotificationEvent::InterestReceived, $other->profile->id));
    $failed = OutboundMessage::query()->sole();
    $this->sms->failing = false;

    [$blocker, $blocked] = $who === 'recipient blocked' ? [$member, $other] : [$other, $member];
    App\Models\Block::query()->forceCreate(['blocker_profile_id' => $blocker->profile->id, 'blocked_profile_id' => $blocked->profile->id]);

    expect(fn () => app(ResendFailedMessage::class)->handle(adminWithRole(), $failed))->toThrow(CommsRefused::class, 'blocked');
    expect($this->sms->notices)->toBe([])->and($failed->refresh()->resent_at)->toBeNull();
})->with(['recipient blocked', 'recipient was blocked']);

it('quiet hours (decisions 2026-11-07 (4)): a resent SMS is queued and waits until they end — never sent in the admin\'s request', function (): void {
    $member = dlMember();
    $this->sms->failing = true;
    Illuminate\Support\Facades\Exceptions::fake();
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    $failed = OutboundMessage::query()->sole();
    $this->sms->failing = false;

    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-08 23:00', 'Asia/Kolkata'));
    Illuminate\Support\Facades\Queue::fake();
    app(ResendFailedMessage::class)->handle(adminWithRole(), $failed);

    expect($this->sms->notices)->toBe([]);
    Illuminate\Support\Facades\Queue::assertPushed(Illuminate\Notifications\SendQueuedNotifications::class, function ($job): bool {
        $delay = $job->delay instanceof DateTimeInterface ? Carbon\CarbonImmutable::instance($job->delay) : null;

        return $job->channels === [SmsChannel::class] && $delay !== null
            && $delay->timezone('Asia/Kolkata')->format('H:i') === '07:00';
    });
});

it('A14 resend needs campaigns.send (403)', function (): void {
    $row = OutboundMessage::factory()->create(['status' => OutboundStatus::Failed]);

    expect(fn () => app(ResendFailedMessage::class)->handle(adminWithRole('read_only'), $row))->toThrow(AuthorizationException::class);
});

it('A14 retention: rows older than 180 days are pruned; a purged member\'s rows are deleted', function (): void {
    $old = OutboundMessage::factory()->create(['created_at' => now()->subDays(181)]);
    $recent = OutboundMessage::factory()->create(['created_at' => now()->subDays(10)]);

    (new PruneOutboundMessages)->handle();
    expect(OutboundMessage::query()->find($old->id))->toBeNull()->and(OutboundMessage::query()->find($recent->id))->not->toBeNull();

    $member = dlMember('+919811600002');
    OutboundMessage::factory()->create(['user_id' => $member->id]);
    $member->forceFill(['status' => UserStatus::Deleted])->save();
    $member->profile->delete();
    $member->delete();
    $this->travel(31)->days();
    app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($member->id));

    expect(OutboundMessage::query()->where('user_id', $member->id)->count())->toBe(0);
});

it('the delivery log screen needs campaigns.view; members are found by code only', function (): void {
    $member = dlMember();
    app(MailChannel::class)->send($member, new ProfileApproved($member->profile->code));
    Notification::fake();

    signInAdmin($this, adminWithRole('support'));   // no campaigns.view
    $this->get(adminUrl('/comms/delivery-log'))->assertForbidden();

    signInAdmin($this, adminWithRole('content_editor'));
    Livewire\Livewire::test(App\Livewire\Admin\Comms\DeliveryLog::class)
        ->assertSee('a•••@example.com')
        ->set('member', $member->profile->code)->assertSee('profile_approved')
        ->set('member', 'anjali.dl@example.com')->assertDontSee('profile_approved');   // not by email
});
