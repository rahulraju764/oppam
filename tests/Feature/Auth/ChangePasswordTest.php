<?php

declare(strict_types=1);

use App\Actions\Auth\ChangePassword;
use App\Actions\Auth\SignInMember;
use App\Events\Account\ForceLogout;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
| P8.2b — change password on the /settings Account tab (PRD M14 / M01): the current password is
| checked and rate-limited (5 wrong → 15 min); the new one must differ; every other device is
| signed out (epoch, remember token, live ForceLogout) and this one stays; notice + audit (never
| the password). Never in a support session.
*/

beforeEach(function (): void {
    fakeSms();
    Notification::fake();
    Event::fake([ForceLogout::class]);
});

function cpwMember(): User
{
    $member = memberWithPhone('+919876533001');
    $member->forceFill(['email' => 'pw@example.com', 'email_verified_at' => now()])->save();

    return $member->refresh();
}

it('R-M14: the right current password changes it; other devices out, this one kept, notice + audit', function (): void {
    $member = cpwMember();
    $request = requestWithSession();
    $epoch = $member->session_epoch;

    app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', $request);

    $member->refresh();
    expect(Hash::check('NewPass2026', $member->password))->toBeTrue()
        ->and($member->session_epoch)->toBe($epoch + 1)
        ->and(session(SignInMember::EPOCH_SESSION_KEY))->toBe($epoch + 1);
    Event::assertDispatched(ForceLogout::class);
    Notification::assertSentTo($member, AccountSecurityChanged::class);
    expect(AuditLog::query()->where('action', 'account.password_changed')->exists())->toBeTrue()
        ->and(json_encode(AuditLog::query()->where('action', 'account.password_changed')->sole()->toArray()))->not->toContain('NewPass2026');
});

it('a wrong current password is refused and, after 5 tries, locked for a while — nothing changes', function (): void {
    $member = cpwMember();
    requestWithSession();

    for ($i = 0; $i < 5; $i++) {
        expect(fn () => app(ChangePassword::class)->handle($member, 'wrong-one-1', 'NewPass2026', request()))
            ->toThrow(AccountChangeRefused::class, 'isn’t right');
    }
    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', request()))
        ->toThrow(AccountChangeRefused::class, 'Too many tries');
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->refresh()->password))->toBeTrue();
    Event::assertNotDispatched(ForceLogout::class);
});

it('the new password must differ from the current one', function (): void {
    $member = cpwMember();
    requestWithSession();

    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, TEST_MEMBER_PASSWORD, request()))
        ->toThrow(AccountChangeRefused::class, 'different');
});

it('change password is refused in an admin support session', function (): void {
    $member = cpwMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    expect(fn () => app(ChangePassword::class)->handle($member, TEST_MEMBER_PASSWORD, 'NewPass2026', requestWithSession()))
        ->toThrow(ImpersonationRestricted::class);
});

it('a suspended account or a bureau login can\'t change the password here (404)', function (string $case): void {
    $member = cpwMember();
    $member->forceFill($case === 'suspended' ? ['status' => App\Enums\UserStatus::Suspended] : ['role' => App\Enums\UserRole::Broker])->save();

    expect(fn () => app(ChangePassword::class)->handle($member->refresh(), TEST_MEMBER_PASSWORD, 'NewPass2026', requestWithSession()))
        ->toThrow(Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
    expect(Hash::check(TEST_MEMBER_PASSWORD, $member->refresh()->password))->toBeTrue();
})->with(['suspended', 'broker']);
