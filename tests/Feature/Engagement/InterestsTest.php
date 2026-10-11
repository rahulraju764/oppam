<?php

declare(strict_types=1);

use App\Actions\Engagement\AcceptInterest;
use App\Actions\Engagement\DeclineInterest;
use App\Actions\Engagement\SendInterest;
use App\Actions\Engagement\SendInterests;
use App\Actions\Engagement\WithdrawInterest;
use App\Data\Search\SearchCriteria;
use App\Domain\Engagement\Connections;
use App\Domain\Media\HoroscopeAccess;
use App\Domain\Media\PhotoAccess;
use App\Domain\Profile\ContactAccessPolicy;
use App\Domain\Profile\ProfileNames;
use App\Enums\ContactAccess;
use App\Enums\Entitlement;
use App\Enums\HoroscopeVisibility;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\NotificationEvent;
use App\Enums\PhoneVisibility;
use App\Enums\PhotoVisibility;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Engagement\InterestChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Billing\QuotaExceeded;
use App\Exceptions\Engagement\EngagementRefused;
use App\Jobs\Engagement\ExpireInterests;
use App\Models\Block;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Favorite;
use App\Models\Interest;
use App\Models\InterestEvent;
use App\Models\Message;
use App\Models\PartnerPreference;
use App\Models\Plan;
use App\Models\PrivacySetting;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Engagement\InterestNotification;
use App\Queries\Engagement\InterestLists;
use App\Services\Admin\Impersonation;
use App\Services\Entitlements\EntitlementService;
use App\Services\Profile\ProfileSearch;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P3.4 — interests (PRD §10 M06, §12 F02, §7.3): the state machine PENDING → ACCEPTED | DECLINED |
| WITHDRAWN | EXPIRED with every allowed and refused transition, the monthly quota, the decline
| cooldown, the contact filter (R-M06-3), what "connected" unlocks, who may do what (the
| security matrix), the live event and the lists.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Event::fake([InterestChanged::class]);
});

function inPlan(User $member, PlanCode $plan): User
{
    Subscription::factory()->create(['profile_id' => $member->profile->id, 'plan_id' => Plan::query()->where('code', $plan->value)->sole()->id]);
    app(EntitlementService::class)->forget($member->profile);

    return $member;
}

function inSend(User $from, Profile $to, ?string $template = null, ?string $custom = null): App\Data\Engagement\InterestResult
{
    return app(SendInterest::class)->handle($from, $to, $template, $custom);
}

function inTarget(): Profile
{
    return Profile::factory()->female()->active()->create();
}

function inPrivacy(Profile $profile, array $values): void
{
    $privacy = PrivacySetting::query()->whereKey($profile->id)->first() ?? new PrivacySetting;
    $privacy->forceFill(['profile_id' => $profile->id, ...$values])->save();
    $profile->unsetRelation('privacySetting');
}

function inUsed(User $member): int
{
    return app(EntitlementService::class)->used($member->profile, Entitlement::InterestsPerMonth);
}

function inStatus(User $from, User $to): ?InterestStatus
{
    return Interest::query()->where('from_profile_id', $from->profile->id)->where('to_profile_id', $to->profile->id)->first()?->status;
}

// ---- Send ---------------------------------------------------------------------------------------

it('F02: sending an interest stores it PENDING for 30 days, uses one of the month\'s interests and tells the other member', function (): void {
    $this->travelTo(now()->startOfMinute());
    $groom = groom();
    $bride = bride();

    $result = inSend($groom, $bride->profile, 'T01');

    $interest = Interest::query()->sole();
    expect($result->state)->toBe(Connections::SENT)->and($result->changed)->toBeTrue()
        ->and($interest->status)->toBe(InterestStatus::Pending)
        ->and($interest->message)->toBe('I liked your profile and would like to know more about you.')
        ->and($interest->sent_at->equalTo(now()))->toBeTrue()
        ->and($interest->expires_at->equalTo(now()->addDays(30)))->toBeTrue()
        ->and(InterestEvent::query()->where('interest_id', $interest->id)->pluck('status')->all())->toBe([InterestStatus::Pending])
        ->and(InterestEvent::query()->value('actor_profile_id'))->toBe($groom->profile->id)
        ->and(inUsed($groom))->toBe(1);

    Notification::assertSentTo($bride, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestReceived && $n->otherProfileId === $groom->profile->id);
    Notification::assertNotSentTo($groom, InterestNotification::class);
    Event::assertDispatched(InterestChanged::class, fn (InterestChanged $e): bool => $e->toUserId === $bride->id && $e->broadcastWith() === ['code' => $groom->profile->code, 'state' => 'received']);
    Event::assertDispatched(InterestChanged::class, fn (InterestChanged $e): bool => $e->toUserId === $groom->id && $e->broadcastWith() === ['code' => $bride->profile->code, 'state' => 'sent']);
});

it('R-M06-1: sending again while it is pending changes nothing — no second charge, no second notification', function (): void {
    $groom = groom();
    $bride = bride();

    inSend($groom, $bride->profile);
    $again = inSend($groom, $bride->profile);

    expect($again->changed)->toBeFalse()->and($again->state)->toBe(Connections::SENT)
        ->and(Interest::query()->count())->toBe(1)->and(inUsed($groom))->toBe(1);
    Notification::assertSentToTimes($bride, InterestNotification::class, 1);
});

it('§7.3: a Free member sends 3 interests a month — the 4th is refused and nothing is written', function (): void {
    $free = groom();
    foreach (range(1, 3) as $i) {
        inSend($free, inTarget());
    }

    expect(fn () => inSend($free, inTarget()))->toThrow(QuotaExceeded::class)
        ->and(Interest::query()->count())->toBe(3)->and(InterestEvent::query()->count())->toBe(3)->and(inUsed($free))->toBe(3);
});

it('M06: the note is a ready message by code, or the member\'s own on a paid plan — never contact details', function (): void {
    $free = groom();
    $gold = inPlan(groom('+919800000081'), PlanCode::Gold);

    expect(fn () => inSend($free, inTarget(), 'NOPE'))->toThrow(ValidationException::class)
        ->and(fn () => inSend($free, inTarget(), null, 'Hello, I would like to talk.'))->toThrow(EngagementRefused::class)
        ->and(fn () => inSend($gold, inTarget(), null, 'Call me on 98470 12345'))->toThrow(ValidationException::class)
        ->and(fn () => inSend($gold, inTarget(), null, 'write to me at a@b.com'))->toThrow(ValidationException::class)
        ->and(fn () => inSend($gold, inTarget(), null, str_repeat('a', 301)))->toThrow(ValidationException::class)
        ->and(Interest::query()->count())->toBe(0);

    inSend($gold, inTarget(), 'T02', '  Our families are from the same town.  ');
    inSend($free, inTarget());

    expect(Interest::query()->where('from_profile_id', $gold->profile->id)->value('message'))->toBe('Our families are from the same town.')
        ->and(Interest::query()->where('from_profile_id', $free->profile->id)->value('message'))->toBeNull();
});

it('R-M06-3: a member whose contact filter excludes the sender is not sent the interest — with a clear message', function (): void {
    $groom = groom();
    $bride = bride();
    $preference = PartnerPreference::query()->where('profile_id', $bride->profile->id)->first() ?? new PartnerPreference;
    $preference->forceFill(['profile_id' => $bride->profile->id, 'age_min' => 60, 'age_max' => 70])->save();
    inPrivacy($bride->profile, ['contact_filter_enabled' => true]);

    $refusal = null;
    try {
        inSend($groom, $bride->profile->refresh());
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }

    expect($refusal?->reason)->toBe(EngagementRefused::CONTACT_FILTER)
        ->and($refusal?->getMessage())->toContain('match their partner preferences')
        ->and(Interest::query()->count())->toBe(0)->and(inUsed($groom))->toBe(0);
    Notification::assertNothingSent();
});

it('rate limit: interests per minute (A15 setting)', function (): void {
    Setting::factory()->keyed(SettingKey::InterestsMaxPerMinute, 2)->create();
    Settings::flush();
    $gold = inPlan(groom(), PlanCode::Gold);
    inSend($gold, inTarget());
    inSend($gold, inTarget());

    expect(fn () => inSend($gold, inTarget()))->toThrow(EngagementRefused::class)->and(Interest::query()->count())->toBe(2);

    $this->travel(61)->seconds();
    expect(inSend($gold, inTarget())->changed)->toBeTrue();
});

// ---- Accept -------------------------------------------------------------------------------------

it('F02: accepting connects the two — conversation, both participants and the system message, once', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);
    Notification::fake();
    Event::fake([InterestChanged::class]);

    $conversation = app(AcceptInterest::class)->handle($bride, $groom->profile);

    $interest = Interest::query()->sole();
    expect($interest->status)->toBe(InterestStatus::Accepted)->and($interest->responded_at)->not->toBeNull()
        ->and($conversation->pair_key)->toBe(Conversation::pairKey($groom->profile->id, $bride->profile->id))
        ->and($conversation->interest_id)->toBe($interest->id)
        ->and(ConversationParticipant::query()->where('conversation_id', $conversation->id)->pluck('profile_id')->sort()->values()->all())
        ->toBe(collect([$groom->profile->id, $bride->profile->id])->sort()->values()->all());

    $message = Message::query()->sole();
    expect($message->type)->toBe(MessageType::System)->and($message->body)->toBe('You are now connected')
        ->and($message->sender_profile_id)->toBeNull()
        ->and($conversation->refresh()->last_message_id)->toBe($message->id)
        ->and(InterestEvent::query()->orderBy('id')->pluck('status')->all())->toBe([InterestStatus::Pending, InterestStatus::Accepted])
        ->and(app(Connections::class)->isConnected($groom->profile, $bride->profile))->toBeTrue()
        ->and(app(Connections::class)->isConnected($bride->profile, $groom->profile))->toBeTrue()
        ->and(inUsed($bride))->toBe(0);   // receiving and accepting is free

    Notification::assertSentTo($groom, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestAccepted);
    Event::assertDispatched(InterestChanged::class, fn (InterestChanged $e): bool => $e->toUserId === $groom->id && $e->state === 'connected');
    Event::assertDispatched(InterestChanged::class, fn (InterestChanged $e): bool => $e->toUserId === $bride->id && $e->state === 'connected');
});

it('F02: when both want it, sending to someone who already sent you an interest accepts theirs — free of charge', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);

    $result = inSend($bride, $groom->profile);

    expect($result->connected)->toBeTrue()->and($result->state)->toBe(Connections::CONNECTED)
        ->and(Interest::query()->count())->toBe(1)->and(inStatus($groom, $bride))->toBe(InterestStatus::Accepted)
        ->and(inUsed($bride))->toBe(0)->and(Conversation::query()->count())->toBe(1);

    expect(fn () => inSend($groom, $bride->profile))->toThrow(EngagementRefused::class)   // already connected
        ->and(fn () => inSend($bride, $groom->profile))->toThrow(EngagementRefused::class);
});

it('M06: once connected the full name, ACCEPTED_ONLY photos, phone and horoscope open — and only then', function (): void {
    $groom = groom();
    $bride = bride();
    $bride->profile->forceFill(['first_name' => 'Anjali', 'last_name' => 'Nair'])->save();
    inPrivacy($bride->profile, ['photo_visibility' => PhotoVisibility::AcceptedOnly, 'phone_visibility' => PhoneVisibility::AcceptedOnly, 'horoscope_visibility' => HoroscopeVisibility::AcceptedOnly]);
    $profile = $bride->profile->refresh();
    $stranger = groom('+919800000080');

    expect(app(ProfileNames::class)->forViewer($profile, $groom))->toBe('Anjali N.')
        ->and(app(PhotoAccess::class)->canSeeClearly($profile, $groom))->toBeFalse()
        ->and(app(HoroscopeAccess::class)->canView($profile, $groom))->toBeFalse()
        ->and(app(ContactAccessPolicy::class)->decide($profile, $groom->profile))->toBe(ContactAccess::AcceptedOnly);

    inSend($groom, $profile);
    expect(app(ProfileNames::class)->forViewer($profile, $groom))->toBe('Anjali N.');   // pending is not connected

    app(AcceptInterest::class)->handle($bride, $groom->profile);

    expect(app(ProfileNames::class)->forViewer($profile, $groom))->toBe('Anjali Nair')
        ->and(app(ProfileNames::class)->forViewer($groom->profile, $bride))->toBe($groom->profile->fullName())
        ->and(app(PhotoAccess::class)->canSeeClearly($profile, $groom))->toBeTrue()
        ->and(app(HoroscopeAccess::class)->canView($profile, $groom))->toBeTrue()
        ->and(app(ContactAccessPolicy::class)->decide($profile, $groom->profile))->not->toBe(ContactAccess::AcceptedOnly)
        ->and(app(ProfileNames::class)->forViewer($profile, $stranger))->toBe('Anjali N.')
        ->and(app(PhotoAccess::class)->canSeeClearly($profile, $stranger))->toBeFalse();
});

// ---- Decline, withdraw, expire --------------------------------------------------------------------

it('F02: declining tells the sender politely and blocks a re-send for 90 days; after that the same row is used again', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);
    Notification::fake();

    app(DeclineInterest::class)->handle($bride, $groom->profile);

    expect(inStatus($groom, $bride))->toBe(InterestStatus::Declined);
    Notification::assertSentTo($groom, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestDeclined
        && str_contains($n->content($groom)->title, 'not interested at this time'));

    $refusal = null;
    try {
        $this->travel(89)->days();
        inSend($groom, $bride->profile);
    } catch (EngagementRefused $e) {
        $refusal = $e;
    }
    expect($refusal?->reason)->toBe(EngagementRefused::COOLDOWN)->and(inUsed($groom))->toBe(0);   // a new month by now: nothing charged

    $this->travel(2)->days();
    expect(inSend($groom, $bride->profile)->changed)->toBeTrue()
        ->and(Interest::query()->count())->toBe(1)->and(inStatus($groom, $bride))->toBe(InterestStatus::Pending)
        ->and(Interest::query()->sole()->responded_at)->toBeNull()
        ->and(InterestEvent::query()->orderBy('id')->pluck('status')->all())->toBe([InterestStatus::Pending, InterestStatus::Declined, InterestStatus::Pending]);

    // The decliner herself may reach out any time.
    $other = bride('+919800000079');
    inSend($groom, $other->profile);
    app(DeclineInterest::class)->handle($other, $groom->profile);
    expect(inSend($other, $groom->profile)->changed)->toBeTrue();
});

it('M06: withdrawing takes a pending interest back — nobody is notified and the month\'s interest is not given back', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);
    Notification::fake();
    Event::fake([InterestChanged::class]);

    app(WithdrawInterest::class)->handle($groom, $bride->profile);

    expect(inStatus($groom, $bride))->toBe(InterestStatus::Withdrawn)->and(inUsed($groom))->toBe(1);
    Notification::assertNothingSent();
    Event::assertDispatched(InterestChanged::class, fn (InterestChanged $e): bool => $e->toUserId === $bride->id && $e->state === 'none');

    expect(inSend($groom, $bride->profile)->changed)->toBeTrue()->and(Interest::query()->count())->toBe(1)->and(inUsed($groom))->toBe(2);
});

it('F02: an interest without a reply for 30 days expires — hourly job, idempotent, no refund, nobody notified', function (): void {
    $groom = groom();
    $bride = bride();
    $other = bride('+919800000078');
    inSend($groom, $bride->profile);
    $this->travel(10)->days();
    inSend($groom, $other->profile);
    Notification::fake();
    $this->travel(20)->days();
    $this->travel(1)->minutes();

    // Past its expiry but not swept yet: it can no longer be accepted.
    expect(fn () => app(AcceptInterest::class)->handle($bride, $groom->profile))->toThrow(EngagementRefused::class);

    (new ExpireInterests)->handle();
    (new ExpireInterests)->handle();

    expect(inStatus($groom, $bride))->toBe(InterestStatus::Expired)->and(inStatus($groom, $other))->toBe(InterestStatus::Pending)
        ->and(InterestEvent::query()->where('status', InterestStatus::Expired->value)->count())->toBe(1)
        ->and(InterestEvent::query()->where('status', InterestStatus::Expired->value)->value('actor_profile_id'))->toBeNull();
    Notification::assertNothingSent();

    expect(inSend($groom, $bride->profile)->changed)->toBeTrue();   // may be sent again (a new month's interest)
});

it('R-M08-2: withdrawing and sending again does not notify the same member twice in a day', function (): void {
    $gold = inPlan(groom(), PlanCode::Gold);
    $bride = bride();

    foreach (range(1, 4) as $i) {
        inSend($gold, $bride->profile);
        app(WithdrawInterest::class)->handle($gold, $bride->profile);
    }
    inSend($gold, $bride->profile);

    expect(inStatus($gold, $bride))->toBe(InterestStatus::Pending)->and(inUsed($gold))->toBe(5);   // each send still costs
    Notification::assertSentToTimes($bride, InterestNotification::class, 1);

    app(WithdrawInterest::class)->handle($gold, $bride->profile);
    $this->travel(1)->days();
    inSend($gold, $bride->profile);
    Notification::assertSentToTimes($bride, InterestNotification::class, 2);
});

it('a pending interest past its expiry no longer counts, even before the hourly job marks it', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);
    $this->travel(30)->days();
    $this->travel(5)->minutes();
    $connections = app(Connections::class);

    expect($connections->stateWith($groom->profile, $bride->profile))->toBe(Connections::NONE)
        ->and($connections->stateWith($bride->profile, $groom->profile))->toBe(Connections::NONE);

    // She reaches out herself: a new interest of her own — his stale one is not "accepted" by it.
    expect(inSend($bride, $groom->profile)->connected)->toBeFalse()
        ->and(inStatus($bride, $groom))->toBe(InterestStatus::Pending)
        ->and(Conversation::query()->count())->toBe(0);

    // He sends again without waiting for the job: the old send is closed in the history first.
    $third = bride('+919800000051');
    Interest::factory()->between($groom->profile, $third->profile)->create(['sent_at' => now()->subDays(31), 'expires_at' => now()->subDay()]);
    expect(inSend($groom, $third->profile)->changed)->toBeTrue()
        ->and(InterestEvent::query()->whereIn('interest_id', Interest::query()->select('id')->where('to_profile_id', $third->profile->id))
            ->orderBy('id')->pluck('status')->all())->toBe([InterestStatus::Expired, InterestStatus::Pending]);
});

it('a pair can never hold an accepted interest and a new pending one: the write itself re-checks under the lock', function (): void {
    $groom = groom();
    $bride = bride();
    Interest::factory()->between($bride->profile, $groom->profile)->accepted()->create();
    $store = new ReflectionMethod(SendInterest::class, 'store');

    expect(fn () => $store->invoke(app(SendInterest::class), $groom->profile, $bride->profile, null))->toThrow(EngagementRefused::class)
        ->and(Interest::query()->count())->toBe(1)->and(inUsed($groom))->toBe(0);

    // …and a crossing interest that arrived after the first look is answered, not doubled.
    $other = bride('+919800000050');
    Interest::factory()->between($other->profile, $groom->profile)->create();
    expect($store->invoke(app(SendInterest::class), $groom->profile, $other->profile, null))->toBe('crossed')
        ->and(Interest::query()->where('from_profile_id', $groom->profile->id)->count())->toBe(0);
});

it('M06: a block made after connecting closes everything the connection had opened', function (): void {
    $groom = groom();
    $bride = bride();
    inPrivacy($bride->profile, ['horoscope_visibility' => HoroscopeVisibility::AcceptedOnly, 'phone_visibility' => PhoneVisibility::AcceptedOnly]);
    inSend($groom, $bride->profile->refresh());
    app(AcceptInterest::class)->handle($bride, $groom->profile);
    expect(app(HoroscopeAccess::class)->canView($bride->profile->refresh(), $groom))->toBeTrue();

    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);

    expect(app(HoroscopeAccess::class)->canView($bride->profile, $groom))->toBeFalse()
        ->and(app(App\Domain\Media\PhotoUrls::class)->forViewer($bride->profile, $groom))->toBe([])
        ->and(fn () => app(App\Actions\Profile\ViewContact::class)->handle($groom, $bride->profile))->toThrow(NotFoundHttpException::class)
        ->and(app(App\Domain\Profile\ProfileVisibility::class)->canView($bride->profile, $groom))->toBeFalse();
});

it('a suspended member can no longer accept an interest', function (): void {
    $groom = groom();
    $bride = bride();
    inSend($groom, $bride->profile);
    $bride->forceFill(['status' => UserStatus::Suspended])->save();

    expect(fn () => app(AcceptInterest::class)->handle($bride->refresh(), $groom->profile))->toThrow(NotFoundHttpException::class)
        ->and(inStatus($groom, $bride))->toBe(InterestStatus::Pending);
});

it('R-M06-6: "my favourites only" is inert in an admin support session — the search never becomes the bookmark list', function (): void {
    $me = groom();
    $fav = inTarget();
    $plain = inTarget();
    Favorite::factory()->between($me->profile, $fav)->create();
    $search = fn (): array => app(ProfileSearch::class)->query($me->profile, SearchCriteria::fromInput(['favorites' => '1']))->pluck('code')->sort()->values()->all();

    expect($search())->toBe([$fav->code]);

    session([Impersonation::SESSION_KEY => 'imp-1']);
    expect($search())->toBe(collect([$fav->code, $plain->code])->sort()->values()->all());
});

it('M06 bulk send never accepts on the member\'s behalf: a ticked profile that sent its own interest is skipped', function (): void {
    $me = groom();
    $waiting = bride();
    $fresh = inTarget();
    Interest::factory()->between($waiting->profile, $me->profile)->create();

    $result = app(SendInterests::class)->handle($me, [$waiting->profile->code, $fresh->code], 'T01');

    expect($result)->toBe(['sent' => 1, 'skipped' => 1, 'quotaReached' => false])
        ->and(inStatus($waiting, $me))->toBe(InterestStatus::Pending)->and(Conversation::query()->count())->toBe(0);
});

it('F02: ExpireInterests runs every hour', function (): void {
    $event = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->description, ExpireInterests::class));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});

// ---- Refused transitions (state machine) ------------------------------------------------------------

it('state machine: only PENDING can change', function (InterestStatus $from, string $action): void {
    $groom = groom();
    $bride = bride();
    Interest::factory()->between($groom->profile, $bride->profile)->status($from)->create();

    $act = fn () => match ($action) {
        'accept' => app(AcceptInterest::class)->handle($bride, $groom->profile),
        'decline' => app(DeclineInterest::class)->handle($bride, $groom->profile),
        'withdraw' => app(WithdrawInterest::class)->handle($groom, $bride->profile),
    };

    expect($act)->toThrow(EngagementRefused::class)->and(inStatus($groom, $bride))->toBe($from)
        ->and(InterestEvent::query()->count())->toBe(0)->and(Conversation::query()->count())->toBe(0);
    Notification::assertNothingSent();
})->with([
    'accepted' => [InterestStatus::Accepted], 'declined' => [InterestStatus::Declined],
    'withdrawn' => [InterestStatus::Withdrawn], 'expired' => [InterestStatus::Expired],
])->with(['accept', 'decline', 'withdraw']);

it('IDOR: only the member it was sent to can answer an interest, and only its sender can withdraw it', function (): void {
    $groom = groom();
    $bride = bride();
    $outsider = bride('+919800000077');
    inSend($groom, $bride->profile);

    expect(fn () => app(AcceptInterest::class)->handle($groom, $bride->profile))->toThrow(NotFoundHttpException::class)      // the sender "accepting" his own
        ->and(fn () => app(AcceptInterest::class)->handle($outsider, $groom->profile))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(DeclineInterest::class)->handle($outsider, $groom->profile))->toThrow(NotFoundHttpException::class)
        ->and(fn () => app(WithdrawInterest::class)->handle($bride, $groom->profile))->toThrow(NotFoundHttpException::class)   // the receiver "withdrawing"
        ->and(fn () => app(WithdrawInterest::class)->handle($outsider, $bride->profile))->toThrow(NotFoundHttpException::class)
        ->and(inStatus($groom, $bride))->toBe(InterestStatus::Pending);
});

// ---- Who may send (security matrix) ------------------------------------------------------------------

it('refuses to send with a 404 and writes nothing', function (string $case): void {
    $groom = groom();
    $bride = bride();

    $target = match ($case) {
        'own profile' => $groom->profile,
        'same gender' => groom('+919800000076')->profile,
        'target not ACTIVE' => tap($bride->profile, fn (Profile $p) => $p->forceFill(['status' => ProfileStatus::Hidden])->save()),
        'sender blocked the target' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $p->id])),
        'target blocked the sender' => tap($bride->profile, fn (Profile $p) => Block::factory()->create(['blocker_profile_id' => $p->id, 'blocked_profile_id' => $groom->profile->id])),
        'suspended sender' => tap($bride->profile, fn () => $groom->forceFill(['status' => UserStatus::Suspended])->save()),
    };

    expect(fn () => inSend($groom->refresh(), $target))->toThrow(NotFoundHttpException::class)
        ->and(Interest::query()->count())->toBe(0)->and(inUsed($groom))->toBe(0);
    Notification::assertNothingSent();
    Event::assertNotDispatched(InterestChanged::class);
})->with(['own profile', 'same gender', 'target not ACTIVE', 'sender blocked the target', 'target blocked the sender', 'suspended sender']);

it('a member whose own profile is not approved yet cannot send an interest', function (): void {
    $pending = memberWithPhone('+919800000075', ProfileStatus::PendingReview);
    $pending->profile->forceFill(['gender' => App\Enums\Gender::Male])->save();

    expect(fn () => inSend($pending->refresh(), bride()->profile))->toThrow(EngagementRefused::class)->and(Interest::query()->count())->toBe(0);
});

it('a block made after the interest was sent: it can no longer be accepted (404), but it can still be declined or withdrawn', function (): void {
    $groom = groom();
    $bride = bride();
    $other = bride('+919800000074');
    inSend($groom, $bride->profile);
    inSend($groom, $other->profile);
    Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]);
    Block::factory()->create(['blocker_profile_id' => $other->profile->id, 'blocked_profile_id' => $groom->profile->id]);

    expect(fn () => app(AcceptInterest::class)->handle($bride, $groom->profile))->toThrow(NotFoundHttpException::class)
        ->and(Conversation::query()->count())->toBe(0);

    app(DeclineInterest::class)->handle($bride, $groom->profile);
    app(WithdrawInterest::class)->handle($groom, $other->profile);
    expect(inStatus($groom, $bride))->toBe(InterestStatus::Declined)->and(inStatus($groom, $other))->toBe(InterestStatus::Withdrawn);
});

it('P1.7b: an admin support session can do none of it for the member', function (): void {
    $groom = groom();
    $bride = bride();
    $other = bride('+919800000073');
    Interest::factory()->between($groom->profile, $bride->profile)->create();
    Interest::factory()->between($other->profile, $groom->profile)->create();
    session([Impersonation::SESSION_KEY => 'imp-1']);

    expect(fn () => inSend($groom, inTarget()))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(WithdrawInterest::class)->handle($groom, $bride->profile))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(AcceptInterest::class)->handle($groom, $other->profile))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(DeclineInterest::class)->handle($groom, $other->profile))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(DeclineInterest::class)->many($groom, [$other->profile->code]))->toThrow(ImpersonationRestricted::class)
        ->and(fn () => app(SendInterests::class)->handle($groom, [$bride->profile->code]))->toThrow(ImpersonationRestricted::class)
        ->and(Interest::query()->where('status', InterestStatus::Pending->value)->count())->toBe(2);
});

// ---- Several at once ----------------------------------------------------------------------------------

it('M06 bulk decline: only the member\'s own pending received interests; junk and foreign codes are skipped', function (): void {
    $bride = bride();
    $a = groom('+919800000072');
    $b = groom('+919800000071');
    $c = groom('+919800000070');
    $someoneElse = bride('+919800000069');
    inSend($a, $bride->profile);
    inSend($b, $bride->profile);
    inSend($c, $someoneElse->profile);
    Notification::fake();

    $declined = app(DeclineInterest::class)->many($bride, [$a->profile->code, $b->profile->code, $c->profile->code, 'junk', 'OPM99999999', ['x']]);

    expect($declined)->toBe(2)->and(inStatus($a, $bride))->toBe(InterestStatus::Declined)->and(inStatus($b, $bride))->toBe(InterestStatus::Declined)
        ->and(inStatus($c, $someoneElse))->toBe(InterestStatus::Pending);
    Notification::assertSentToTimes($a, InterestNotification::class, 1);
    Notification::assertNotSentTo($c, InterestNotification::class);
});

it('M06 /favorites "send interest to all selected": stops at the month\'s quota, skips who can\'t receive one', function (): void {
    $free = groom();
    $targets = collect(range(1, 5))->map(fn (): Profile => inTarget());
    $targets->each(fn (Profile $t) => Favorite::factory()->between($free->profile, $t)->create());
    Interest::factory()->between($free->profile, $targets[0])->create();   // already sent

    $result = app(SendInterests::class)->handle($free, [...$targets->pluck('code')->all(), 'junk'], 'T01');

    expect($result)->toBe(['sent' => 3, 'skipped' => 1, 'quotaReached' => true])
        ->and(Interest::query()->where('from_profile_id', $free->profile->id)->count())->toBe(4)->and(inUsed($free))->toBe(3);
});

// ---- The live event -----------------------------------------------------------------------------------

it('§9.4: InterestChanged goes to the member\'s own private channel on the broadcasts queue with a code and a state only', function (): void {
    $groom = groom();
    $bride = bride();
    $event = new InterestChanged($bride->id, $bride->profile->id, $groom->profile->id, $groom->profile->code, 'received');

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$bride->id)
        ->and($event->broadcastAs())->toBe('interest.changed')->and($event->queue)->toBe('broadcasts')
        ->and($event->broadcastWith())->toBe(['code' => $groom->profile->code, 'state' => 'received'])
        ->and(json_encode($event->broadcastWith()))->not->toContain($groom->profile->id)->not->toContain($groom->id)
        ->and($event->broadcastWhen())->toBeTrue();

    Block::factory()->create(['blocker_profile_id' => $groom->profile->id, 'blocked_profile_id' => $bride->profile->id]);
    expect($event->broadcastWhen())->toBeFalse();
});

it('R-M08-1: the interest notifications name the other member as the recipient may see them', function (): void {
    $groom = groom();
    $bride = bride();
    $groom->profile->forceFill(['first_name' => 'Arun', 'last_name' => 'Menon'])->save();

    $received = (new InterestNotification(NotificationEvent::InterestReceived, $groom->profile->id))->content($bride);
    expect($received->title)->toBe('Arun M. sent you an interest')->and($received->actorCode)->toBe($groom->profile->code)
        ->and($received->url)->toBe(route('member.interests'))
        ->and(fn () => new InterestNotification(NotificationEvent::ProfileLiked, $groom->profile->id))->toThrow(InvalidArgumentException::class);

    Block::factory()->create(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    expect((new InterestNotification(NotificationEvent::InterestReceived, $groom->profile->id))->shouldSend($bride, 'x'))->toBeFalse();
});

// ---- Lists and search ----------------------------------------------------------------------------------

it('/interests lists: received and sent by status with counts — a withdrawn interest disappears for the receiver, blocked members are never listed', function (): void {
    $me = groom();
    $lists = app(InterestLists::class);
    [$pending, $accepted, $declined, $expired, $withdrawn, $blocked] = collect(range(1, 6))->map(fn (): Profile => inTarget())->all();

    Interest::factory()->between($pending, $me->profile)->create(['sent_at' => now()->subHour()]);
    Interest::factory()->between($accepted, $me->profile)->accepted()->create();
    Interest::factory()->between($declined, $me->profile)->declined()->create();
    Interest::factory()->between($expired, $me->profile)->status(InterestStatus::Expired)->create();
    Interest::factory()->between($withdrawn, $me->profile)->status(InterestStatus::Withdrawn)->create();
    Interest::factory()->between($blocked, $me->profile)->create();
    Block::factory()->create(['blocker_profile_id' => $blocked->id, 'blocked_profile_id' => $me->profile->id]);
    Interest::factory()->between($me->profile, $pending)->status(InterestStatus::Withdrawn)->create();
    Interest::factory()->between($me->profile, $accepted)->create(['message' => 'Hello there']);

    $codes = fn (string $box, string $status): array => $lists->box($me->profile, $box, $status)->pluck('profiles.code')->sort()->values()->all();

    expect($codes('received', 'pending'))->toBe([$pending->code])
        ->and($codes('received', 'accepted'))->toBe([$accepted->code])
        ->and($codes('received', 'declined'))->toBe([$declined->code])
        ->and($codes('received', 'expired'))->toBe([$expired->code])
        ->and($codes('received', 'all'))->toBe(collect([$pending, $accepted, $declined, $expired])->pluck('code')->sort()->values()->all())
        ->and($codes('sent', 'pending'))->toBe([$accepted->code])
        ->and($codes('sent', 'expired'))->toBe([$pending->code])   // my own withdrawn one stays in my history
        ->and($lists->counts($me->profile))->toBe([
            'received' => ['all' => 4, 'pending' => 1, 'accepted' => 1, 'declined' => 1, 'expired' => 1],
            'sent' => ['all' => 2, 'pending' => 1, 'accepted' => 0, 'declined' => 0, 'expired' => 1],
        ])
        ->and($lists->box($me->profile, 'sent', 'pending')->first()->getAttribute('interest_message'))->toBe('Hello there');
});

it('search (M04 / M06): "my favourites only" and "hide profiles I\'ve sent an interest to"', function (): void {
    $me = groom();
    [$fav, $contacted, $declinedByThem, $plain] = [inTarget(), inTarget(), inTarget(), inTarget()];
    Favorite::factory()->between($me->profile, $fav)->create();
    Favorite::factory()->between(groom('+919800000068')->profile, $plain)->create();   // someone else's bookmark
    Interest::factory()->between($me->profile, $contacted)->create();
    Interest::factory()->between($me->profile, $declinedByThem)->declined()->create();
    $search = fn (array $in): array => app(ProfileSearch::class)->query($me->profile, SearchCriteria::fromInput($in))->pluck('code')->all();

    expect($search(['favorites' => '1']))->toBe([$fav->code])
        ->and($search(['hide_contacted' => '1']))->toContain($fav->code, $plain->code, $declinedByThem->code)->not->toContain($contacted->code)
        ->and($search([]))->toContain($contacted->code)
        ->and(SearchCriteria::fromInput(['favorites' => '1', 'hide_contacted' => '1'])->toInput())->toMatchArray(['favorites' => true, 'hide_contacted' => true]);
});
