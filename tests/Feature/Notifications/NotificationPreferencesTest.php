<?php

declare(strict_types=1);

use App\Actions\Notifications\UpdateNotificationPreferences;
use App\Data\Notifications\NotificationChoiceData;
use App\Data\Notifications\NotificationSettingsData;
use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\NotificationPreference;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Profile\ProfileApproved;
use App\Notifications\Support\InAppChannel;
use App\Notifications\Support\NotificationRouter;
use App\Notifications\Support\SmsChannel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P8.2a — /settings → Notifications (PRD M14 / M08): the events × channels matrix, saved through
| UpdateNotificationPreferences and proved in NotificationRouter (which channels a notification
| really uses); SMS quiet hours, one window for everyone from A15 settings, proved in smsDelay.
| Owner decisions 2026-11-06 / 11-07: a member can switch channels off, never add one; quiet hours
| are not per member and hold SMS only.
*/

beforeEach(function (): void {
    seedMasters();
    Cache::flush();
});

function npMember(): User
{
    $member = memberWithPhone('+919866611001');
    $member->forceFill(['email' => 'np@example.com', 'email_verified_at' => now(), 'phone_verified_at' => now()])->save();

    return $member->refresh();
}

/** @param  list<NotificationChoiceData>  $choices */
function npSave(User $member, array $choices): void
{
    app(UpdateNotificationPreferences::class)->handle($member, new NotificationSettingsData($choices));
}

function npQuietHours(int $from, int $until): void
{
    Setting::factory()->keyed(SettingKey::NotificationsQuietFrom, $from)->create();
    Setting::factory()->keyed(SettingKey::NotificationsQuietUntil, $until)->create();
    App\Support\Facades\Settings::flush();
}

function npChannels(User $member, NotificationEvent $event): array
{
    return app(NotificationRouter::class)->channels($member->refresh(), $event);
}

it('R-M14 a channel switched off is no longer used for that event — and only that event', function (): void {
    $member = npMember();
    expect(npChannels($member, NotificationEvent::InterestReceived))->toBe([InAppChannel::class, 'mail', SmsChannel::class]);

    npSave($member, [new NotificationChoiceData(NotificationEvent::InterestReceived, inApp: true, email: false, sms: false)]);

    expect(npChannels($member, NotificationEvent::InterestReceived))->toBe([InAppChannel::class])
        ->and(npChannels($member, NotificationEvent::InterestAccepted))->toBe([InAppChannel::class, 'mail', SmsChannel::class]);
});

it('R-M14 everything off: the member gets nothing for that event', function (): void {
    $member = npMember();

    npSave($member, [new NotificationChoiceData(NotificationEvent::DailyMatchesReady, false, false, false)]);

    expect(npChannels($member, NotificationEvent::DailyMatchesReady))->toBe([]);
});

it('R-M08-3 mandatory events can\'t be switched off: nothing is stored and every channel stays', function (): void {
    $member = npMember();

    npSave($member, [new NotificationChoiceData(NotificationEvent::ProfileApproved, false, false, false)]);

    expect(NotificationPreference::query()->where('user_id', $member->id)->count())->toBe(0)
        ->and(npChannels($member, NotificationEvent::ProfileApproved))->toBe([InAppChannel::class, 'mail', SmsChannel::class]);
});

it('decisions 2026-11-07: a channel the event never uses can\'t be added (no per-like email, no SMS without a template)', function (): void {
    $member = npMember();

    npSave($member, [
        new NotificationChoiceData(NotificationEvent::ProfileLiked, true, true, true),
        new NotificationChoiceData(NotificationEvent::DailyMatchesReady, true, true, true),
    ]);

    $liked = NotificationPreference::query()->where('user_id', $member->id)->where('event', 'profile_liked')->sole();
    expect($liked->email)->toBeFalse()->and($liked->sms)->toBeFalse()->and($liked->push)->toBeFalse()
        ->and(npChannels($member, NotificationEvent::ProfileLiked))->toBe([InAppChannel::class])
        ->and(npChannels($member, NotificationEvent::DailyMatchesReady))->toBe([InAppChannel::class, 'mail']);
});

it('bureau events are never written from the member matrix, and a repeated event counts once', function (): void {
    $member = npMember();

    npSave($member, [
        new NotificationChoiceData(NotificationEvent::CommissionEarned, false, false, false),
        new NotificationChoiceData(NotificationEvent::MutualLike, false, false, false),
        new NotificationChoiceData(NotificationEvent::MutualLike, true, false, false),
    ]);

    expect(NotificationPreference::query()->where('user_id', $member->id)->pluck('event')->all())->toBe(['mutual_like'])
        ->and(NotificationPreference::query()->where('event', 'mutual_like')->sole()->in_app)->toBeFalse();
});

it('the matrix lists member events only, grouped like /notifications', function (): void {
    $events = NotificationEvent::forMemberSettings();

    expect($events)->not->toContain(NotificationEvent::PayoutPaid)->not->toContain(NotificationEvent::FollowUpDue)
        ->and($events)->toContain(NotificationEvent::InterestReceived)->toContain(NotificationEvent::AdminAnnouncement)
        ->and($events[0]->group())->toBe(App\Enums\NotificationGroup::Interests);
    foreach (NotificationEvent::cases() as $event) {
        expect($event->label())->not->toBe('');
    }
});

// ---- Quiet hours ------------------------------------------------------------------------------------

it('M08 quiet hours (A15, one window for everyone): an SMS waits until the window ends (IST)', function (string $now, int $from, int $until, ?string $sendsAt): void {
    npQuietHours($from, $until);

    $delay = app(NotificationRouter::class)->smsDelay(CarbonImmutable::parse($now, 'Asia/Kolkata'));

    expect($delay?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe($sendsAt);
})->with([
    'wraps midnight, late evening' => ['2026-11-07 23:30', 21, 8, '2026-11-08 08:00'],
    'wraps midnight, small hours' => ['2026-11-08 06:00', 21, 8, '2026-11-08 08:00'],
    'wraps midnight, just before' => ['2026-11-07 20:59', 21, 8, null],
    'wraps midnight, at the end' => ['2026-11-08 08:00', 21, 8, null],
    'daytime window, inside' => ['2026-11-07 14:10', 13, 15, '2026-11-07 15:00'],
    'daytime window, outside' => ['2026-11-07 16:00', 13, 15, null],
    'same start and end = none' => ['2026-11-08 03:00', 5, 5, null],
]);

it('M08 quiet hours change without a deploy: a new A15 window applies to the next SMS', function (): void {
    $at = CarbonImmutable::parse('2026-11-07 21:30', 'Asia/Kolkata');
    expect(app(NotificationRouter::class)->smsDelay($at))->toBeNull();   // default 22–07

    npQuietHours(21, 6);

    expect(app(NotificationRouter::class)->smsDelay($at)?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-11-08 06:00');
});

it('quiet hours hold the SMS of a mandatory event too, and never the in-app or email copy (M08)', function (): void {
    $member = npMember();
    npQuietHours(20, 9);
    $this->travelTo(CarbonImmutable::parse('2026-11-07 21:00', 'Asia/Kolkata'));

    $delay = (new ProfileApproved($member->profile->code))->withDelay($member->refresh(), SmsChannel::class);

    expect(array_keys($delay))->toBe([SmsChannel::class])
        ->and($delay[SmsChannel::class]->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-11-08 09:00');
});

it('the A15 quiet-hour values are IST hours 0–23', function (int $value, bool $valid): void {
    $rules = ['v' => SettingKey::NotificationsQuietFrom->rules()];

    expect(Illuminate\Support\Facades\Validator::make(['v' => $value], $rules)->passes())->toBe($valid)
        ->and(Illuminate\Support\Facades\Validator::make(['v' => $value], ['v' => SettingKey::NotificationsQuietUntil->rules()])->passes())->toBe($valid);
})->with([[0, true], [23, true], [-1, false], [24, false]]);

// ---- Who may save -------------------------------------------------------------------------------------

it('rule 10: past members.settings_changes_per_hour a save that switches something on is refused; an opt-out always works', function (): void {
    Setting::factory()->keyed(SettingKey::MembersSettingsChangesPerHour, 5)->create();
    $member = npMember();
    $off = [new NotificationChoiceData(NotificationEvent::MutualLike, false, false, false)];
    $on = [new NotificationChoiceData(NotificationEvent::MutualLike, true, false, false)];

    for ($i = 0; $i < 5; $i++) {
        npSave($member, $off);   // opt-out: not counted
        npSave($member, $on);    // switches in-app back on: counted
    }

    npSave($member, $off);       // an opt-out still works past the limit
    $row = fn () => NotificationPreference::query()->where('user_id', $member->id)->where('event', 'mutual_like')->sole();
    expect($row()->in_app)->toBeFalse();

    expect(fn () => npSave($member, $on))->toThrow(SettingsRefused::class);
    expect($row()->in_app)->toBeFalse();
});

it('refused in an admin support session', function (): void {
    $member = npMember();
    $this->actingAs($member, 'web');
    chatImpersonate($member);

    expect(fn () => npSave($member, [new NotificationChoiceData(NotificationEvent::MutualLike, false, false, false)]))->toThrow(ImpersonationRestricted::class);
    expect(NotificationPreference::query()->count())->toBe(0);
});

it('a suspended account or a bureau login can\'t save member preferences (404)', function (string $case): void {
    $member = npMember();
    if ($case === 'suspended') {
        $member->forceFill(['status' => UserStatus::Suspended])->save();
    } else {
        $member->forceFill(['role' => App\Enums\UserRole::Broker])->save();
    }

    expect(fn () => npSave($member->refresh(), []))->toThrow(NotFoundHttpException::class);
})->with(['suspended', 'broker']);
