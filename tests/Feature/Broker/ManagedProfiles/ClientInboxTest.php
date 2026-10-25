<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\RespondToInterestForClient;
use App\Actions\Broker\ManagedProfiles\SendInterestForClient;
use App\Actions\Engagement\DeclineInterest;
use App\Actions\Engagement\SendInterest;
use App\Domain\Broker\BureauInterestAllowance;
use App\Domain\Chat\ChatAccess;
use App\Enums\BureauRole;
use App\Enums\ChatSendState;
use App\Enums\InterestStatus;
use App\Enums\NotificationEvent;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Events\Broker\ClientInterestReceived;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Chat\ChatRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\Conversation;
use App\Models\Interest;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Engagement\InterestNotification;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P7.4 — the Client Inbox (§11A B.6, R-M13-34 / R-M13-35, decisions 2026-10-25): a bureau answers
| interests for its unclaimed managed client (Owner, Manager, Telecaller) and sends them against
| its monthly allowance; chat stays closed for the other member, with the notice. Scope (foreign /
| unassigned → 404) and the role matrix as everywhere in the portal.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
});

/** @return array{0: User, 1: Profile} the bureau Owner and its ACTIVE female client */
function inboxClient(): array
{
    $owner = bureauLogin();

    return [$owner, managedProfile(staffOf($owner)->broker)];
}

function suitor(string $e164 = '+919800000099'): User
{
    return groom($e164);
}

function respondFor(User $login, Profile $client, Profile $from, bool $accept = true): void
{
    app(RespondToInterestForClient::class)->handle($login, $client->code, $from->code, $accept);
}

function sendFor(User $login, Profile $client, Profile $to, ?string $template = 'T01'): App\Data\Engagement\InterestResult
{
    return app(SendInterestForClient::class)->handle($login, $client->code, $to->code, $template);
}

// ---- Received: accept / decline on the client's behalf -----------------------------------------------

it('B.6: Owner, Manager and an assigned Telecaller accept for the client — connected, the member told, audited with the staff user', function (BureauRole $role): void {
    Notification::fake();
    [$owner, $client] = inboxClient();
    $login = $role === BureauRole::Owner ? $owner : bureauLogin($role, broker: staffOf($owner)->broker);
    if ($role === BureauRole::Telecaller) {
        $client->forceFill(['assigned_staff_id' => staffOf($login)->id])->save();
    }
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');

    respondFor($login, $client, $member->profile);

    expect(Interest::query()->where('from_profile_id', $member->profile->id)->value('status'))->toBe(InterestStatus::Accepted)
        ->and(Conversation::query()->where('pair_key', Conversation::pairKey($member->profile->id, $client->id))->exists())->toBeTrue();
    Notification::assertSentTo($member, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestAccepted);

    $audit = AuditLog::query()->where('action', 'broker.interest_accepted')->sole();
    expect($audit->actor_id)->toBe($login->id)->and($audit->subject_label)->toBe($client->code)->and($audit->after['from'])->toBe($member->profile->code);
})->with([BureauRole::Owner, BureauRole::Manager, BureauRole::Telecaller]);

it('B.6: declining for the client tells the member and starts their cooldown', function (): void {
    Notification::fake();
    [$owner, $client] = inboxClient();
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');

    respondFor($owner, $client, $member->profile, accept: false);

    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Declined)
        ->and(AuditLog::query()->where('action', 'broker.interest_declined')->exists())->toBeTrue();
    Notification::assertSentTo($member, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestDeclined);
    expect(fn () => app(SendInterest::class)->handle($member, $client->refresh(), 'T01'))->toThrow(EngagementRefused::class);
});

it('B.2: Data Entry may not answer the client inbox; an Owner switch-off removes it', function (): void {
    [$owner, $client] = inboxClient();
    $broker = staffOf($owner)->broker;
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');

    expect(fn () => respondFor(bureauLogin(BureauRole::DataEntry, broker: $broker), $client, $member->profile))->toThrow(HttpException::class);
    expect(fn () => respondFor(bureauLogin(BureauRole::Manager, broker: $broker, disabled: ['client_inbox.respond']), $client, $member->profile))->toThrow(HttpException::class);
    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Pending);
});

it('R-M13-6 / R-M13-20: another bureau’s client, or a client not assigned to an assigned-only Telecaller → 404', function (): void {
    [$owner, $client] = inboxClient();
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');

    $other = bureauLogin();
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker);
    staffOf($telecaller)->forceFill(['profile_scope' => ProfileScope::Assigned])->save();

    expect(fn () => respondFor($other, $client, $member->profile))->toThrow(ModelNotFoundException::class);
    expect(fn () => respondFor($telecaller, $client, $member->profile))->toThrow(ModelNotFoundException::class);
    expect(fn () => sendFor($other, $client, suitor('+919800000101')->profile))->toThrow(ModelNotFoundException::class);
    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Pending);
});

it('accepting needs the member to still be visible to the client (hidden since → 404); unknown codes → 404', function (): void {
    [$owner, $client] = inboxClient();
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');
    $member->profile->forceFill(['status' => ProfileStatus::Hidden])->save();

    expect(fn () => respondFor($owner, $client, $member->profile->refresh()))->toThrow(NotFoundHttpException::class);
    expect(fn () => app(RespondToInterestForClient::class)->handle($owner, $client->code, 'OPM99999999', true))->toThrow(NotFoundHttpException::class);
    // Declining still works (as for members: an interest from a since-hidden profile can be cleared).
    respondFor($owner, $client, $member->profile, accept: false);
    expect(Interest::query()->sole()->status)->toBe(InterestStatus::Declined);
});

it('a suspended client can’t answer interests', function (): void {
    [$owner, $client] = inboxClient();
    $member = suitor();
    app(SendInterest::class)->handle($member, $client, 'T01');
    $client->forceFill(['status' => ProfileStatus::Suspended])->save();

    expect(fn () => respondFor($owner, $client, $member->profile))->toThrow(ManagedProfileRefused::class);
});

it('B.15: a member’s interest to a managed client reaches the bureau live — the client code only, no user notification', function (): void {
    Event::fake([ClientInterestReceived::class]);
    Notification::fake();
    [, $client] = inboxClient();

    app(SendInterest::class)->handle(suitor(), $client, 'T01');

    Event::assertDispatched(ClientInterestReceived::class, fn (ClientInterestReceived $e): bool => $e->brokerId === $client->managed_by_broker_id
        && $e->broadcastWith() === ['profile' => $client->code]
        && $e->broadcastOn()[0]->name === 'private-broker.'.$client->managed_by_broker_id
        && $e->broadcastAs() === 'client.interest');
    Notification::assertNothingSent();
});

// ---- Chat stays closed ------------------------------------------------------------------------------

it('R-M13-34: after the bureau accepts, the member sees the "managed by a registered bureau" notice and can’t write', function (): void {
    [$owner, $client] = inboxClient();
    $member = chatOnPlan(suitor(), App\Enums\PlanCode::Gold);
    app(SendInterest::class)->handle($member, $client, 'T01');
    respondFor($owner, $client, $member->profile);
    $thread = Conversation::query()->sole();

    $state = app(ChatAccess::class)->sendState($member->profile, $client->refresh(), $thread);

    expect($state)->toBe(ChatSendState::Managed)
        ->and($state->message())->toBe('This profile is managed by a registered bureau. They will contact you or you can reach them after the client joins.');
    expect(fn () => app(App\Actions\Chat\SendMessage::class)->handle($member, $client, 'Hello', (string) Str::uuid()))
        ->toThrow(ChatRefused::class);
});

// ---- Sending for the client -------------------------------------------------------------------------

it('R-M13-35: sending for the client uses the bureau’s monthly allowance, not a plan quota; the member is notified', function (): void {
    Notification::fake();
    [$owner, $client] = inboxClient();
    $member = suitor();

    $result = sendFor($owner, $client, $member->profile);

    expect($result->changed)->toBeTrue()
        ->and(Interest::query()->where('from_profile_id', $client->id)->where('to_profile_id', $member->profile->id)->value('status'))->toBe(InterestStatus::Pending)
        ->and(app(BureauInterestAllowance::class)->used(staffOf($owner)->broker))->toBe(1)
        ->and(DB::table('entitlement_usages')->where('profile_id', $client->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'broker.interest_sent')->where('actor_id', $owner->id)->exists())->toBeTrue();
    Notification::assertSentTo($member, InterestNotification::class, fn (InterestNotification $n): bool => $n->kind === NotificationEvent::InterestReceived);
});

it('R-M13-35: one allowance per bureau across its clients; the next send is refused and nothing is written', function (): void {
    [$owner, $client] = inboxClient();
    $broker = staffOf($owner)->broker;
    $broker->forceFill(['interest_allowance_monthly' => 2])->save();
    $second = managedProfile($broker);
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);

    sendFor($owner, $client, suitor('+919800000101')->profile);
    sendFor($telecaller, $second, suitor('+919800000102')->profile);

    $third = suitor('+919800000103');
    expect(fn () => sendFor($owner, $client, $third->profile))->toThrow(ManagedProfileRefused::class);
    expect(Interest::query()->where('to_profile_id', $third->profile->id)->exists())->toBeFalse()
        ->and(app(BureauInterestAllowance::class)->used($broker->refresh()))->toBe(2)
        ->and(app(BureauInterestAllowance::class)->used(Broker::factory()->create()))->toBe(0);   // another bureau is untouched
});

it('R-M13-35: the allowance renews with the IST calendar month', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-10-31 23:00', 'Asia/Kolkata'));
    [$owner, $client] = inboxClient();
    $broker = staffOf($owner)->broker;
    $broker->forceFill(['interest_allowance_monthly' => 1])->save();
    sendFor($owner, $client, suitor('+919800000101')->profile);
    expect(fn () => sendFor($owner, $client, suitor('+919800000102')->profile))->toThrow(ManagedProfileRefused::class);

    $this->travelTo(Carbon\CarbonImmutable::parse('2026-11-01 00:30', 'Asia/Kolkata'));   // still 31 Oct in UTC

    expect(sendFor($owner, $client, suitor('+919800000103')->profile)->changed)->toBeTrue();
});

it('the client must be ACTIVE to send; the target must be someone the client could see', function (string $case): void {
    [$owner, $client] = inboxClient();
    $target = suitor()->profile;

    match ($case) {
        'client in review' => $client->forceFill(['status' => ProfileStatus::PendingReview])->save(),
        'client deactivated' => $client->forceFill(['status' => ProfileStatus::Hidden])->save(),
        'same gender' => $target->forceFill(['gender' => App\Enums\Gender::Female])->save(),
        'target not active' => $target->forceFill(['status' => ProfileStatus::Hidden])->save(),
        'target is the client' => null,
    };

    $code = $case === 'target is the client' ? $client->code : $target->code;
    expect(fn () => app(SendInterestForClient::class)->handle($owner, $client->code, $code, 'T01'))->toThrow(ManagedProfileRefused::class);
    expect(Interest::query()->count())->toBe(0)
        ->and(app(BureauInterestAllowance::class)->used(staffOf($owner)->broker))->toBe(0);
})->with(['client in review', 'client deactivated', 'same gender', 'target not active', 'target is the client']);

it('a malformed code is a validation error; a code with spaces / lower case is normalised', function (): void {
    [$owner, $client] = inboxClient();
    $target = suitor()->profile;

    expect(fn () => app(SendInterestForClient::class)->handle($owner, $client->code, 'OPM12;DROP', null))->toThrow(ValidationException::class);
    expect(app(SendInterestForClient::class)->handle($owner, $client->code, ' '.strtolower($target->code).' ', null)->changed)->toBeTrue();
});

it('the member rules apply: cooldown after a decline; a pending interest is not charged twice; a crossed interest connects for free', function (): void {
    [$owner, $client] = inboxClient();
    $broker = staffOf($owner)->broker;
    $member = suitor();

    sendFor($owner, $client, $member->profile);
    expect(sendFor($owner, $client, $member->profile)->changed)->toBeFalse();          // already pending: no charge
    app(DeclineInterest::class)->handle($member, $client->refresh());
    expect(fn () => sendFor($owner, $client, $member->profile))->toThrow(EngagementRefused::class);   // cooldown

    $other = suitor('+919800000101');
    app(SendInterest::class)->handle($other, $client, 'T01');                           // they wrote first
    $crossed = sendFor($owner, $client, $other->profile);

    expect($crossed->connected)->toBeTrue()
        ->and(app(BureauInterestAllowance::class)->used($broker))->toBe(1);
});

it('a custom note needs a paid plan — bureau sends use the ready messages', function (): void {
    [$owner, $client] = inboxClient();

    expect(fn () => app(SendInterest::class)->send($client, suitor()->profile, null, 'Call me', fn () => null))
        ->toThrow(EngagementRefused::class);
});

it('member interests are unchanged by the refactor: still charged to the member’s own quota', function (): void {
    $bride = bride();
    $groom = suitor('+919800000101');

    app(SendInterest::class)->handle($groom, $bride->profile, 'T01');

    expect(DB::table('entitlement_usages')->where('profile_id', $groom->profile->id)->value('used'))->toBe(1)
        ->and(DB::table('broker_interest_usage')->count())->toBe(0);
});
