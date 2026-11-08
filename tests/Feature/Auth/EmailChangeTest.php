<?php

declare(strict_types=1);

use App\Actions\Auth\ConfirmEmailChange;
use App\Actions\Auth\RemoveEmail;
use App\Actions\Auth\RequestEmailChange;
use App\Actions\Auth\SignInMember;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\LoginMethod;
use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\AuditLog;
use App\Models\EmailChangeRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Notifications\Auth\EmailChangeRequested;
use App\Notifications\Auth\NewDeviceSignIn;
use App\Notifications\Auth\VerifyEmailAddress;
use App\Notifications\Chat\NewMessagesDigest;
use App\Notifications\Support\NotificationRouter;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;

/*
| P8.2b — the account email (PRD M14 Account; owner decisions 2026-11-08): a single-use, 24-hour
| link to the address asked for proves it; the old address stays until then and is told; taken /
| banned addresses are refused with one generic message (R-M01-4); removing it stops all email. Once an
| address is verified, the emails that waited for verification go out (new-device alert, invoice
| email, chat digest — decisions 2026-10-06 / 2026-10-16).
*/

beforeEach(function (): void {
    fakeSms();
    Notification::fake();
});

function ecMember(?string $email = null, bool $verified = false): User
{
    $member = memberWithPhone('+919876522001');
    $member->forceFill(['email' => $email, 'email_verified_at' => $verified ? now() : null])->save();

    return $member->refresh();
}

/** The link token the member was mailed (the URL's last segment). */
function ecMailedToken(string $address): string
{
    $token = null;
    Notification::assertSentOnDemand(VerifyEmailAddress::class, function (VerifyEmailAddress $n, array $channels, AnonymousNotifiable $to) use ($address, &$token): bool {
        if (($to->routes['mail'] ?? null) !== $address) {
            return false;
        }
        $token = basename($n->url);

        return true;
    });

    return (string) $token;
}

it('R-M14: a link goes to the new address only; opening it verifies the address and notifies the member', function (): void {
    $member = ecMember();

    app(RequestEmailChange::class)->handle($member, '  Asha@Example.COM ');
    $token = ecMailedToken('asha@example.com');
    expect($member->refresh()->email)->toBeNull();   // nothing changes until the link is opened

    app(ConfirmEmailChange::class)->handle($token, null);

    expect($member->refresh()->email)->toBe('asha@example.com')->and($member->email_verified_at)->not->toBeNull()
        ->and(EmailChangeRequest::query()->whereNull('consumed_at')->count())->toBe(0);
    Notification::assertSentTo($member, AccountSecurityChanged::class);
    expect(json_encode(AuditLog::query()->where('action', 'account.email_verified')->sole()->after))->not->toContain('asha@example.com');
});

it('changing a verified address: the old one stays until the link is opened, and is told', function (): void {
    $member = ecMember('old@example.com', verified: true);

    app(RequestEmailChange::class)->handle($member, 'new@example.com');

    Notification::assertSentOnDemand(EmailChangeRequested::class, fn ($n, array $c, AnonymousNotifiable $to): bool => ($to->routes['mail'] ?? null) === 'old@example.com');
    expect($member->refresh()->email)->toBe('old@example.com')->and($member->email_verified_at)->not->toBeNull();

    app(ConfirmEmailChange::class)->handle(ecMailedToken('new@example.com'), $member);
    expect($member->refresh()->email)->toBe('new@example.com');
});

it('an unverified address on the account can be verified with a link to itself', function (): void {
    $member = ecMember('mine@example.com');

    app(RequestEmailChange::class)->handle($member, 'mine@example.com');
    app(ConfirmEmailChange::class)->handle(ecMailedToken('mine@example.com'), null);

    expect($member->refresh()->email_verified_at)->not->toBeNull();
    Notification::assertSentOnDemandTimes(EmailChangeRequested::class, 0);   // nothing to tell: no verified address before
});

it('the already verified address is refused as "same email"', function (): void {
    $member = ecMember('mine@example.com', verified: true);

    expect(fn () => app(RequestEmailChange::class)->handle($member, 'MINE@example.com'))->toThrow(AccountChangeRefused::class);
});

it('R-M01-1 / R-M01-4: an address held by another account or banned is refused with one generic message and gets no mail', function (string $case): void {
    $member = ecMember();
    if ($case === 'another account') {
        memberWithPhone('+919876522009')->forceFill(['email' => 'taken@example.com'])->save();
    } else {
        app(BanList::class)->add(BannedIdentifierType::Email, 'taken@example.com', null, null, null);
    }

    expect(fn () => app(RequestEmailChange::class)->handle($member, 'taken@example.com'))
        ->toThrow(AccountChangeRefused::class, AccountChangeRefused::emailUnavailable()->getMessage());   // same words for every reason

    Notification::assertSentOnDemandTimes(VerifyEmailAddress::class, 0);
    expect(EmailChangeRequest::query()->count())->toBe(0);
})->with(['another account', 'banned']);

it('links: unknown, used and expired ones are refused; a new request makes the older link stop working', function (): void {
    $member = ecMember();
    expect(fn () => app(ConfirmEmailChange::class)->handle(str_repeat('a', 64), null))->toThrow(AccountChangeRefused::class);

    app(RequestEmailChange::class)->handle($member, 'first@example.com');
    $first = ecMailedToken('first@example.com');
    app(RequestEmailChange::class)->handle($member, 'second@example.com');
    expect(fn () => app(ConfirmEmailChange::class)->handle($first, null))->toThrow(AccountChangeRefused::class);

    $second = ecMailedToken('second@example.com');
    app(ConfirmEmailChange::class)->handle($second, null);
    expect(fn () => app(ConfirmEmailChange::class)->handle($second, null))->toThrow(AccountChangeRefused::class);   // single use

    app(RequestEmailChange::class)->handle($member, 'third@example.com');
    $third = ecMailedToken('third@example.com');
    $this->travel((int) config('oppam.auth.email_verify_hours') + 1)->hours();
    expect(fn () => app(ConfirmEmailChange::class)->handle($third, null))->toThrow(AccountChangeRefused::class);
    expect($member->refresh()->email)->toBe('second@example.com');
});

it('opening the link signed in as another member is refused', function (): void {
    $member = ecMember();
    $other = memberWithPhone('+919876522008');
    app(RequestEmailChange::class)->handle($member, 'asha@example.com');

    expect(fn () => app(ConfirmEmailChange::class)->handle(ecMailedToken('asha@example.com'), $other))
        ->toThrow(AccountChangeRefused::class, 'different account');
    expect($member->refresh()->email)->toBeNull();
});

it('the address taken by someone else before the link is opened is refused', function (): void {
    $member = ecMember();
    app(RequestEmailChange::class)->handle($member, 'asha@example.com');
    $token = ecMailedToken('asha@example.com');
    memberWithPhone('+919876522007')->forceFill(['email' => 'asha@example.com'])->save();

    expect(fn () => app(ConfirmEmailChange::class)->handle($token, null))->toThrow(AccountChangeRefused::class);
});

it('a suspended account\'s link does nothing', function (): void {
    $member = ecMember();
    app(RequestEmailChange::class)->handle($member, 'asha@example.com');
    $member->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => app(ConfirmEmailChange::class)->handle(ecMailedToken('asha@example.com'), null))->toThrow(AccountChangeRefused::class);
});

it('rule 10: at most A15 members.email_changes_per_day requests a day', function (): void {
    Setting::factory()->keyed(SettingKey::MembersEmailChangesPerDay, 2)->create();
    $member = ecMember();
    app(RequestEmailChange::class)->handle($member, 'a@example.com');
    app(RequestEmailChange::class)->handle($member, 'b@example.com');

    expect(fn () => app(RequestEmailChange::class)->handle($member, 'c@example.com'))->toThrow(AccountChangeRefused::class);
});

it('refused in an admin support session', function (): void {
    $member = ecMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    expect(fn () => app(RequestEmailChange::class)->handle($member, 'asha@example.com'))->toThrow(ImpersonationRestricted::class);
    expect(fn () => app(RemoveEmail::class)->handle($member))->toThrow(ImpersonationRestricted::class);
});

it('remove email: no more email; an in-app notice; open links stop working', function (): void {
    $member = ecMember('old@example.com', verified: true);
    app(RequestEmailChange::class)->handle($member, 'new@example.com');
    $token = ecMailedToken('new@example.com');

    app(RemoveEmail::class)->handle($member);

    expect($member->refresh()->email)->toBeNull()->and($member->canReceiveAccountMail())->toBeFalse();
    Notification::assertSentTo($member, AccountSecurityChanged::class, fn (AccountSecurityChanged $n): bool => $n->change === App\Enums\AccountSecurityChange::EmailRemoved);
    expect(fn () => app(ConfirmEmailChange::class)->handle($token, null))->toThrow(AccountChangeRefused::class);
});

// ---- The emails that waited for verification --------------------------------------------------------

it('decisions 2026-10-06 / 10-16: invoice email and chat digest go out only once the address is verified', function (): void {
    $member = ecMember('asha@example.com');
    $router = app(NotificationRouter::class);
    expect($router->channels($member, NotificationEvent::PaymentSuccess))->not->toContain('mail')
        ->and((new NewMessagesDigest([['name' => 'Ravi', 'count' => 2]]))->via($member))->toBe([]);

    app(RequestEmailChange::class)->handle($member, 'asha@example.com');
    app(ConfirmEmailChange::class)->handle(ecMailedToken('asha@example.com'), null);
    $member->refresh();

    expect($router->channels($member, NotificationEvent::PaymentSuccess))->toContain('mail')
        ->and((new NewMessagesDigest([['name' => 'Ravi', 'count' => 2]]))->via($member))->toBe(['mail']);
});

it('M01: the new-device alert goes out once the address is verified', function (): void {
    $member = ecMember('asha@example.com');
    app(SignInMember::class)->handle($member, LoginMethod::Password, false, requestWithSession());   // first sign-in
    app(SignInMember::class)->handle($member, LoginMethod::Password, false, requestWithSession());   // new device, unverified
    Notification::assertNotSentTo($member, NewDeviceSignIn::class);

    $member->forceFill(['email_verified_at' => now()])->save();
    app(SignInMember::class)->handle($member->refresh(), LoginMethod::Password, false, requestWithSession());

    Notification::assertSentTo($member, NewDeviceSignIn::class);
});
