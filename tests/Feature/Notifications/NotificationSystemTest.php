<?php

declare(strict_types=1);

use App\Actions\Notifications\UpdateNotifications;
use App\Actions\Profile\RecordProfileView;
use App\Contracts\SmsGateway;
use App\Data\Notifications\NotificationItemData;
use App\Enums\NotificationEvent;
use App\Enums\PlanCode;
use App\Events\Notifications\NotificationsRead;
use App\Jobs\Notifications\PruneOldNotifications;
use App\Models\Block;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\Member\DailyMatchesReady;
use App\Notifications\Profile\ProfileApproved;
use App\Notifications\Profile\ProfileViewedNotification;
use App\Notifications\Support\InAppChannel;
use App\Notifications\Support\NotificationRouter;
use App\Notifications\Support\OncePerDay;
use App\Notifications\Support\SmsChannel;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FakeSmsGateway;

/*
| P3.2 — the notification system (PRD §10 M08): channels per preference with mandatory events and
| SMS quiet hours, one minimal payload (no ids / PII), blocked actors never notified (R-M08-1),
| once per actor per day (R-M08-2), SMS through the gateway (best-effort), own-only read / delete
| with a cross-tab signal, the 180-day prune (R-M08-4), same-site links only.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
});

function ntMember(string $phone = '+919866600001'): User
{
    $member = memberWithPhone($phone);
    $member->forceFill(['email' => 'nt'.substr($phone, -4).'@example.com', 'email_verified_at' => now(), 'phone_verified_at' => now()])->save();

    return $member->refresh();
}

function prefer(User $member, NotificationEvent $event, array $values): void
{
    $pref = new NotificationPreference(['event' => $event->value, ...$values]);
    $pref->user_id = $member->id;
    $pref->save();
}

it('M08: defaults — in-app (database + live), email and SMS as the M08 table says', function (): void {
    $member = ntMember();
    $router = app(NotificationRouter::class);

    expect($router->channels($member, NotificationEvent::ProfileApproved))->toBe([InAppChannel::class, 'mail', SmsChannel::class])
        ->and($router->channels($member, NotificationEvent::DailyMatchesReady))->toBe([InAppChannel::class, 'mail'])
        ->and($router->channels($member, NotificationEvent::ProfileViewed))->toBe([InAppChannel::class]);
});

it('M08 acceptance: with email off for an event, no email — the in-app notification still shows', function (): void {
    $member = ntMember();
    prefer($member, NotificationEvent::DailyMatchesReady, ['email' => false]);

    expect(app(NotificationRouter::class)->channels($member, NotificationEvent::DailyMatchesReady))->toBe([InAppChannel::class]);

    $member->notify(new DailyMatchesReady(3));   // sync queue: really delivered
    expect(DatabaseNotification::query()->where('notifiable_id', $member->id)->where('type', 'daily_matches_ready')->count())->toBe(1);
});

it('R-M08-3: transactional / safety events can\'t be switched off', function (): void {
    $member = ntMember();
    prefer($member, NotificationEvent::ProfileApproved, ['in_app' => false, 'email' => false, 'sms' => false]);

    expect(app(NotificationRouter::class)->channels($member, NotificationEvent::ProfileApproved))->toBe([InAppChannel::class, 'mail', SmsChannel::class]);
});

it('R-M08-3: a mandatory event uses exactly its own channels — a default-off channel (SMS for account_status) is never added', function (): void {
    $member = ntMember();
    $router = app(NotificationRouter::class);

    expect($router->channels($member, NotificationEvent::AccountStatus))->toBe([InAppChannel::class, 'mail']);

    prefer($member, NotificationEvent::AccountStatus, ['in_app' => false, 'email' => false, 'sms' => true]);
    expect($router->channels($member, NotificationEvent::AccountStatus))->toBe([InAppChannel::class, 'mail']);
});

it('a member can switch an optional channel ON as well as off', function (): void {
    $member = ntMember();
    prefer($member, NotificationEvent::ProfileViewed, ['email' => true]);

    expect(app(NotificationRouter::class)->channels($member, NotificationEvent::ProfileViewed))->toBe([InAppChannel::class, 'mail']);
});

it('PRD §9.7.1: the row is written before the live signal, in one job on the notifications queue', function (): void {
    $member = ntMember();
    $notification = new DailyMatchesReady(3);
    $sent = [];
    $broadcaster = Mockery::mock(Illuminate\Contracts\Broadcasting\Broadcaster::class);
    $broadcaster->shouldReceive('broadcast')->once()->andReturnUsing(function (array $channels, string $event, array $payload) use (&$sent, $member): void {
        $sent = ['rows' => DatabaseNotification::query()->where('notifiable_id', $member->id)->count(),
            'channel' => (string) $channels[0], 'event' => $event, 'type' => $payload['type'] ?? null];
    });
    $this->mock(Illuminate\Contracts\Broadcasting\Factory::class)->shouldReceive('connection')->andReturn($broadcaster);
    Queue::fake();   // a second, queued hop for the live signal would show up here

    (new InAppChannel(app(Illuminate\Notifications\Channels\DatabaseChannel::class), app(Illuminate\Contracts\Bus\Dispatcher::class)))
        ->send($member, tap($notification, fn ($n) => $n->id = (string) Str::uuid()));

    expect($sent)->toBe(['rows' => 1, 'channel' => 'private-App.Models.User.'.$member->id,
        'event' => BroadcastNotificationCreated::class, 'type' => 'daily_matches_ready'])
        ->and($notification->viaQueues())->toBe([InAppChannel::class => 'notifications', 'mail' => 'mail', SmsChannel::class => 'notifications']);
    Queue::assertNothingPushed();
});

it('a WebSocket outage never fails the in-app notification: the row is kept, the failure reported', function (): void {
    Exceptions::fake();
    $member = ntMember();
    $this->mock(Illuminate\Contracts\Broadcasting\Factory::class)->shouldReceive('connection')->andThrow(new RuntimeException('reverb is down'));

    $member->notify(new DailyMatchesReady(2));

    expect(DatabaseNotification::query()->where('notifiable_id', $member->id)->count())->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0);   // nothing to replay as a stale toast later
    Exceptions::assertReported(RuntimeException::class);
});

it('SMS in quiet hours is delayed on the SMS channel only', function (): void {
    $member = ntMember();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 23:10', 'Asia/Kolkata'));
    $delay = (new ProfileApproved($member->profile->code))->withDelay($member, SmsChannel::class);

    expect(array_keys($delay))->toBe([SmsChannel::class])
        ->and($delay[SmsChannel::class]->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-10-06 07:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));
    expect((new ProfileApproved($member->profile->code))->withDelay($member, SmsChannel::class))->toBe([]);
});

it('R-M08-1 end to end: a view by a member in a blocked pair leaves no notification', function (): void {
    $viewer = groom();
    $viewed = bride();
    Block::factory()->create(['blocker_profile_id' => $viewed->profile->id, 'blocked_profile_id' => $viewer->profile->id]);

    $viewed->notify(new ProfileViewedNotification($viewer->profile->id));   // sync queue: really attempted

    expect(DatabaseNotification::query()->where('notifiable_id', $viewed->id)->count())->toBe(0);
});

it('R-M08-3: every notification email links to the member\'s notification settings page', function (): void {
    $member = ntMember();

    foreach ([new DailyMatchesReady(2), new ProfileApproved($member->profile->code), new App\Notifications\Account\AdminMessage('Subject here', 'A body of the message.')] as $notification) {
        $mail = $notification->toMail($member);   // lines land before or after the button, depending on the mail
        expect(implode(' ', array_map(strval(...), [...$mail->introLines, ...$mail->outroLines])))->toContain(route('member.notifications'));
    }
});

it('email only to a verified address, SMS only to a verified phone', function (): void {
    $member = ntMember();
    $member->forceFill(['email_verified_at' => null, 'phone_verified_at' => null])->save();

    expect(app(NotificationRouter::class)->channels($member->refresh(), NotificationEvent::ProfileApproved))->toBe([InAppChannel::class]);
});

it('SMS quiet hours: 22:00–07:00 IST wait until 07:00', function (string $now, ?string $until): void {
    $delay = app(NotificationRouter::class)->smsDelay(CarbonImmutable::parse($now, 'Asia/Kolkata'));

    expect($delay?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe($until);
})->with([
    'late evening' => ['2026-10-05 23:10', '2026-10-06 07:00'],
    'small hours' => ['2026-10-06 03:00', '2026-10-06 07:00'],
    'daytime' => ['2026-10-05 12:00', null],
    'just after 07:00' => ['2026-10-05 07:00', null],
]);

it('one payload for database and live: event type, display text, internal link — no ids, phone or email', function (): void {
    $member = ntMember();
    $notification = new ProfileApproved($member->profile->code);
    $payload = $notification->toArray($member);

    expect(array_keys($payload))->toBe(['type', 'actor_code', 'actor_name', 'actor_photo_thumb', 'title', 'body', 'url', 'icon'])
        ->and($payload['type'])->toBe('profile_approved')
        ->and($notification->databaseType($member))->toBe('profile_approved')
        ->and($notification->broadcastType())->toBe('profile_approved')
        ->and(json_encode($payload))->not->toContain((string) $member->id)->not->toContain((string) $member->profile->id)
        ->not->toContain((string) $member->phone)->not->toContain((string) $member->email)
        ->and($notification->toBroadcast($member)->data)->toBe($payload);
});

it('R-M08-1: a blocked pair (either direction) never hears about each other', function (string $who): void {
    $viewer = ntMember('+919866600002');
    $viewed = bride();
    $who === 'viewer blocked'
        ? Block::factory()->create(['blocker_profile_id' => $viewer->profile->id, 'blocked_profile_id' => $viewed->profile->id])
        : Block::factory()->create(['blocker_profile_id' => $viewed->profile->id, 'blocked_profile_id' => $viewer->profile->id]);

    expect((new ProfileViewedNotification($viewer->profile->id))->shouldSend($viewed, InAppChannel::class))->toBeFalse();
})->with(['viewer blocked', 'viewed blocked']);

it('M15 / M08: a profile view notifies once per viewer per day; the viewer is named for Gold+ only', function (): void {
    Notification::fake();
    $viewer = groom();
    $viewed = bride();

    app(RecordProfileView::class)->handle($viewer, $viewed->profile);
    app(RecordProfileView::class)->handle($viewer, $viewed->profile);   // second visit today: no second notification
    Notification::assertSentToTimes($viewed, ProfileViewedNotification::class, 1);

    $free = (new ProfileViewedNotification($viewer->profile->id))->content($viewed);
    expect($free->title)->toBe('Someone viewed your profile')->and($free->actorCode)->toBeNull();

    App\Models\Subscription::factory()->create(['profile_id' => $viewed->profile->id,
        'plan_id' => App\Models\Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    app(App\Services\Entitlements\EntitlementService::class)->forget($viewed->profile);
    $gold = (new ProfileViewedNotification($viewer->profile->id))->content($viewed);
    expect($gold->actorCode)->toBe($viewer->profile->code)->and($gold->title)->toContain('viewed your profile');
});

it('R-M08-2: OncePerDay lets one notification per actor, type and IST day through', function (): void {
    Cache::flush();
    $once = app(OncePerDay::class);

    expect($once->claim('u1', NotificationEvent::ProfileLiked, 'OPM1'))->toBeTrue()
        ->and($once->claim('u1', NotificationEvent::ProfileLiked, 'OPM1'))->toBeFalse()
        ->and($once->claim('u1', NotificationEvent::ProfileLiked, 'OPM2'))->toBeTrue();

    $this->travel(1)->days();
    expect($once->claim('u1', NotificationEvent::ProfileLiked, 'OPM1'))->toBeTrue();
});

it('SMS goes through the gateway with the event as template key; a failure is reported, never thrown', function (): void {
    $sms = new FakeSmsGateway;
    app()->instance(SmsGateway::class, $sms);
    $member = ntMember();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kolkata'));

    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    expect($sms->notices)->toBe([['to' => $member->phone, 'key' => 'profile_approved', 'variables' => [$member->profile->code]]]);

    Exceptions::fake();
    $sms->failing = true;
    app(SmsChannel::class)->send($member, new ProfileApproved($member->profile->code));
    Exceptions::assertReported(App\Exceptions\Sms\SmsDeliveryFailed::class);
});

it('read / delete touch only the member\'s own notifications and tell the other tabs', function (): void {
    Event::fake([NotificationsRead::class]);
    $me = ntMember();
    $other = ntMember('+919866600003');
    $me->notify(new DailyMatchesReady(1));
    $me->notify(new DailyMatchesReady(2));
    $other->notify(new DailyMatchesReady(3));
    $theirs = DatabaseNotification::query()->where('notifiable_id', $other->id)->value('id');
    $mine = DatabaseNotification::query()->where('notifiable_id', $me->id)->value('id');
    $actions = app(UpdateNotifications::class);

    expect($actions->markRead($me, (string) $theirs))->toBe(0)
        ->and($actions->delete($me, (string) $theirs))->toBeFalse()
        ->and($actions->unreadCount($other))->toBe(1);

    expect($actions->markRead($me, (string) $mine))->toBe(1)->and($actions->unreadCount($me))->toBe(1);
    Event::assertDispatched(NotificationsRead::class, fn (NotificationsRead $e): bool => $e->userId === (string) $me->id && $e->unreadCount === 1
        && $e->broadcastAs() === 'notifications.read' && $e->broadcastWith() === ['unread_count' => 1]);

    expect($actions->markRead($me))->toBe(1)->and($actions->unreadCount($me))->toBe(0)
        ->and($actions->delete($me, (string) $mine))->toBeTrue();
});

it('R-M08-4: notifications older than 180 days are pruned, newer ones kept', function (): void {
    $member = ntMember();
    $member->notify(new DailyMatchesReady(1));
    $member->notify(new DailyMatchesReady(2));
    DatabaseNotification::query()->where('notifiable_id', $member->id)->orderBy('id')->limit(1)->update(['created_at' => now()->subDays(181)]);

    (new PruneOldNotifications)->handle();

    expect(DatabaseNotification::query()->where('notifiable_id', $member->id)->count())->toBe(1);
});

it('a stored link can only ever point inside the site', function (string $url, bool $kept): void {
    $notification = new DatabaseNotification;
    $notification->forceFill(['id' => 'x', 'type' => 'profile_approved', 'data' => ['title' => 'T', 'url' => $url], 'created_at' => now()]);

    expect(NotificationItemData::fromModel($notification)->url === $url)->toBe($kept);
})->with([
    'relative path' => ['/matches', true],
    'other site' => ['https://evil.example/phish', false],
    'protocol-relative' => ['//evil.example/x', false],
    'backslash (read as // by browsers)' => ['/\\evil.example/x', false],
    'control character' => ["/matches\n//evil.example", false],
    'javascript' => ['javascript:alert(1)', false],
]);

it('an absolute link to this site is kept', function (): void {
    $notification = new DatabaseNotification;
    $notification->forceFill(['id' => 'x', 'type' => 'profile_approved', 'data' => ['title' => 'T', 'url' => route('member.dashboard')], 'created_at' => now()]);

    expect(NotificationItemData::fromModel($notification)->url)->toBe(route('member.dashboard'));
});

it('A04: approving a profile now leaves an in-app notification too', function (): void {
    seedAdminRoles();
    $member = memberWithPhone('+919866600004', App\Enums\ProfileStatus::PendingReview);
    $item = App\Models\ModerationItem::factory()->create(['profile_id' => $member->profile->id, 'type' => App\Enums\ModerationItemType::ProfileNew]);
    Illuminate\Support\Facades\Bus::fake([App\Jobs\Matching\GenerateDailyMatchesChunk::class]);

    app(App\Actions\Moderation\ApproveProfile::class)->handle(adminWithRole(), $item);

    expect(DatabaseNotification::query()->where('notifiable_id', $member->id)->where('type', 'profile_approved')->exists())->toBeTrue();
});
