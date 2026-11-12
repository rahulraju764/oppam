<?php

declare(strict_types=1);

use App\Actions\Admin\Comms\SaveJourney;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Safety\BlockProfile;
use App\Enums\CommsChannel;
use App\Enums\InterestStatus;
use App\Enums\JourneyKey;
use App\Enums\JourneySendStatus;
use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Jobs\Comms\RunJourneys;
use App\Models\AutomatedJourney;
use App\Models\Broker;
use App\Models\DailyMatch;
use App\Models\Interest;
use App\Models\JourneySend;
use App\Models\NotificationPreference;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use App\Notifications\Comms\JourneyReminder;
use App\Notifications\Support\SmsChannel;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P8.3b2 — A14 automated journeys (PRD §11 A14): incomplete wizard (1 / 3 / 7 d), pending interest
| (3 d), inactive 14 d with new matches, win-back 30 d after a plan ended. Configured, not coded
| (on / off, offsets, channel — SaveJourney); sent by the daily RunJourneys.
| Done when: each journey reaches only members who qualify, each step once, at most one journey
| message a member a day, and opt-outs, blocks and quiet hours are respected.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-11-12 10:30', 'Asia/Kolkata'));
});

/** Switch a journey on (optionally with other offsets / channel), as an admin would. */
function journeyOn(JourneyKey $key, ?array $offsets = null, CommsChannel $channel = CommsChannel::Email): AutomatedJourney
{
    $journey = AutomatedJourney::query()->where('key', $key->value)->sole();
    $journey->forceFill(['is_active' => true, 'offsets' => $offsets ?? $key->defaultOffsets(), 'channel' => $channel])->save();

    return $journey;
}

function runJourneys(): void
{
    app()->call([new RunJourneys, 'handle']);
}

/** A member whose profile is still a draft, registered $daysAgo days ago. */
function journeyDraftMember(int $daysAgo, array $user = []): User
{
    $member = User::factory()->create(['email_verified_at' => now(), ...$user]);
    Profile::factory()->for($member)->draft()->create(['created_at' => now()->subDays($daysAgo)]);

    return $member->refresh();
}

function remindersTo(User $member): int
{
    return Notification::sent($member, JourneyReminder::class)->count();
}

it('A14 journeys: the migration seeds the four journeys, all OFF — a fresh install sends nothing', function (): void {
    expect(AutomatedJourney::query()->pluck('is_active', 'key')->all())
        ->toEqualCanonicalizing(['pending_interest' => false, 'wizard_incomplete' => false, 'inactive_matches' => false, 'win_back' => false]);

    journeyDraftMember(1);
    runJourneys();

    Notification::assertNothingSent();
    expect(JourneySend::query()->count())->toBe(0);
});

it('A14 incomplete wizard (1 d, 3 d, 7 d): one reminder per step, on its day — never twice, never after the profile is finished', function (): void {
    journeyOn(JourneyKey::WizardIncomplete);
    $member = journeyDraftMember(0);
    $finished = journeyDraftMember(0);
    $start = CarbonImmutable::now();

    foreach ([1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 2, 7 => 3, 8 => 3, 12 => 3] as $day => $expected) {
        $this->travelTo($start->addDays($day));
        if ($day === 3) {
            $finished->profile->forceFill(['status' => App\Enums\ProfileStatus::PendingReview])->save();
        }
        runJourneys();
        runJourneys();   // a second run the same day changes nothing
        expect(remindersTo($member))->toBe($expected, "day {$day}");
    }

    expect(remindersTo($finished))->toBe(1)   // day 1 only: submitted before day 3
        ->and(JourneySend::query()->where('user_id', $member->id)->orderBy('step')->pluck('step')->all())->toBe([1, 3, 7]);
});

it('A14 catch-up: a run missed for a day still sends the step the next day — once', function (): void {
    journeyOn(JourneyKey::WizardIncomplete, [3]);
    $member = journeyDraftMember(4);   // day 3 was yesterday; no run happened

    runJourneys();
    $this->travel(1)->day();
    runJourneys();

    expect(remindersTo($member))->toBe(1);
});

it('A14 pending interest (3 d): the receiver of an unanswered interest is reminded — without the sender\'s name, code or photo', function (): void {
    journeyOn(JourneyKey::PendingInterest, channel: CommsChannel::InApp);
    $receiver = campaignMember();
    $sender = campaignMember(female: false);
    Interest::factory()->between($sender->profile, $receiver->profile)->create(['sent_at' => now()->subDays(3)]);
    $answered = campaignMember();
    Interest::factory()->between($sender->profile, $answered->profile)->accepted()->create(['sent_at' => now()->subDays(3)]);
    $recent = campaignMember();
    Interest::factory()->between($sender->profile, $recent->profile)->create(['sent_at' => now()->subDay()]);

    runJourneys();

    expect(remindersTo($receiver))->toBe(1)
        ->and(remindersTo($answered))->toBe(0)
        ->and(remindersTo($recent))->toBe(0)
        ->and(remindersTo($sender))->toBe(0);

    Notification::assertSentTo($receiver, JourneyReminder::class, function (JourneyReminder $n) use ($receiver, $sender): bool {
        $payload = json_encode($n->toArray($receiver), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $n->via($receiver) === [App\Notifications\Support\InAppChannel::class]
            && ! str_contains($payload, $sender->profile->code)
            && ! str_contains($payload, $sender->profile->id)
            && ! str_contains($payload, $sender->profile->first_name)
            && str_contains($payload, route('member.interests'));
    });
});

it('A14 pending interest: never about a member in a blocked pair (R-M08-1), nor from a sender no longer active', function (): void {
    journeyOn(JourneyKey::PendingInterest);
    $receiver = campaignMember();
    $blocked = campaignMember(female: false);
    Interest::factory()->between($blocked->profile, $receiver->profile)->create(['sent_at' => now()->subDays(3)]);
    $suspended = campaignMember(female: false);
    Interest::factory()->between($suspended->profile, $receiver->profile)->create(['sent_at' => now()->subDays(3)]);
    $suspended->profile->forceFill(['status' => App\Enums\ProfileStatus::Suspended])->save();

    app(BlockProfile::class)->handle($receiver, $blocked->profile);
    // The block declines / withdraws pending interests in many flows; keep this one PENDING to prove the job's own check.
    Interest::query()->update(['status' => InterestStatus::Pending->value]);

    runJourneys();

    expect(remindersTo($receiver))->toBe(0)
        ->and(JourneySend::query()->where('user_id', $receiver->id)->sole()->status)->toBe(JourneySendStatus::SkippedUnreachable);
});

it('A14 pending interest: an interest sent again after a withdrawal is a new subject — its own reminder', function (): void {
    journeyOn(JourneyKey::PendingInterest);
    $receiver = campaignMember();
    $sender = campaignMember(female: false);
    $interest = Interest::factory()->between($sender->profile, $receiver->profile)->create(['sent_at' => now()->subDays(3)]);

    runJourneys();
    $this->travel(10)->days();
    $interest->forceFill(['sent_at' => now()->subDays(3), 'expires_at' => now()->addDays(27)])->save();
    runJourneys();

    expect(remindersTo($receiver))->toBe(2);
});

it('A14 inactive 14 d: only a member with new daily matches since their last visit is told, with the count', function (): void {
    journeyOn(JourneyKey::InactiveMatches);
    $withMatches = campaignMember(profile: ['last_active_at' => now()->subDays(14)]);
    foreach (range(1, 3) as $d) {
        DailyMatch::factory()->create(['profile_id' => $withMatches->profile->id, 'match_date' => now('Asia/Kolkata')->subDays($d)->toDateString()]);
    }
    $nothingNew = campaignMember(profile: ['last_active_at' => now()->subDays(14)]);
    DailyMatch::factory()->create(['profile_id' => $nothingNew->profile->id, 'match_date' => now('Asia/Kolkata')->subDays(20)->toDateString()]);
    $recentlySeen = campaignMember(profile: ['last_active_at' => now()->subDays(2)]);
    DailyMatch::factory()->create(['profile_id' => $recentlySeen->profile->id, 'match_date' => now('Asia/Kolkata')->toDateString()]);

    runJourneys();

    expect(remindersTo($withMatches))->toBe(1)
        ->and(remindersTo($nothingNew))->toBe(0)
        ->and(remindersTo($recentlySeen))->toBe(0);
    Notification::assertSentTo($withMatches, JourneyReminder::class, fn (JourneyReminder $n): bool => $n->count === 3);
});

it('A14 win-back 30 d: a member whose plan ended 30 days ago and who has no plan now — not one who renewed', function (): void {
    journeyOn(JourneyKey::WinBack);
    $plan = App\Models\Plan::factory()->create();
    $lapsed = campaignMember();
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $lapsed->profile->id, 'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);
    $renewed = campaignMember();
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $renewed->profile->id, 'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $renewed->profile->id, 'starts_at' => now()->subDays(10), 'ends_at' => now()->addDays(20)]);
    $stillPaid = campaignMember();
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $stillPaid->profile->id, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);   // ACTIVE row not yet expired by the job

    runJourneys();

    expect(remindersTo($lapsed))->toBe(1)
        ->and(remindersTo($renewed))->toBe(0)
        ->and(remindersTo($stillPaid))->toBe(0);
});

it('A14 win-back (review fix, Major): after a renewal chain it counts from the LAST plan\'s end — not the moment the later plan ends', function (): void {
    journeyOn(JourneyKey::WinBack);
    $plan = App\Models\Plan::factory()->create();
    $member = campaignMember();
    // Plan A ended 30 days ago; the member renewed straight away (plan B), which ended this morning.
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $member->profile->id, 'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(60), 'ends_at' => now()->subDays(30)]);
    Subscription::factory()->create(['plan_id' => $plan->id, 'profile_id' => $member->profile->id, 'status' => SubscriptionStatus::Expired, 'starts_at' => now()->subDays(30), 'ends_at' => now()->subHours(2)]);

    runJourneys();
    $this->travel(1)->day();
    runJourneys();   // plan A's catch-up day
    expect(remindersTo($member))->toBe(0);

    $this->travel(29)->days();   // 30 days after plan B ended
    runJourneys();
    expect(remindersTo($member))->toBe(1);
});

it('A14 one journey message a member a day: the pending interest wins; the other journey goes the next day (catch-up)', function (): void {
    journeyOn(JourneyKey::PendingInterest);
    journeyOn(JourneyKey::InactiveMatches);
    $member = campaignMember(profile: ['last_active_at' => now()->subDays(14)]);
    DailyMatch::factory()->create(['profile_id' => $member->profile->id, 'match_date' => now('Asia/Kolkata')->subDay()->toDateString()]);
    Interest::factory()->between(campaignMember(female: false)->profile, $member->profile)->create(['sent_at' => now()->subDays(3)]);

    runJourneys();
    Notification::assertSentTo($member, JourneyReminder::class, fn (JourneyReminder $n): bool => $n->journey === JourneyKey::PendingInterest);
    expect(remindersTo($member))->toBe(1);

    $this->travel(1)->day();
    runJourneys();
    expect(remindersTo($member))->toBe(2);
    Notification::assertSentTo($member, JourneyReminder::class, fn (JourneyReminder $n): bool => $n->journey === JourneyKey::InactiveMatches);
});

it('A14 opt-outs: a member who switched the reminder off on the journey\'s channel is skipped and recorded — never sent on another channel', function (): void {
    journeyOn(JourneyKey::WizardIncomplete, [1]);
    $optedOut = journeyDraftMember(1);
    (new NotificationPreference)->forceFill([
        'user_id' => $optedOut->id, 'event' => NotificationEvent::JourneyWizardReminder->value,
        'in_app' => true, 'email' => false, 'sms' => false, 'push' => false,
    ])->save();
    $noEmail = journeyDraftMember(1, ['email' => null, 'email_verified_at' => null]);
    $wants = journeyDraftMember(1);

    runJourneys();

    expect(remindersTo($optedOut))->toBe(0)
        ->and(remindersTo($noEmail))->toBe(0)
        ->and(remindersTo($wants))->toBe(1)
        ->and(JourneySend::query()->where('user_id', $optedOut->id)->sole()->status)->toBe(JourneySendStatus::SkippedOptedOut)
        ->and(JourneySend::query()->where('user_id', $noEmail->id)->sole()->status)->toBe(JourneySendStatus::SkippedUnreachable);

    Notification::assertSentTo($wants, JourneyReminder::class, fn (JourneyReminder $n): bool => $n->via($wants) === ['mail']);
});

it('A14 journeys skip suspended, closed and bureau-run profiles', function (): void {
    journeyOn(JourneyKey::WizardIncomplete, [1]);
    $suspended = journeyDraftMember(1, ['status' => UserStatus::Suspended]);
    $closed = journeyDraftMember(1);
    $closed->delete();
    $managed = journeyDraftMember(1);
    $managed->profile->forceFill(['managed_by_broker_id' => Broker::factory()->create()->id])->save();

    runJourneys();

    Notification::assertNothingSent();
    expect(JourneySend::query()->count())->toBe(0);
});

it('A14 SMS journeys: opt-in only, and an SMS in quiet hours waits until they end (M08)', function (): void {
    journeyOn(JourneyKey::WizardIncomplete, [1], CommsChannel::Sms);
    $default = journeyDraftMember(1);                       // never switched journey SMS on
    $optedIn = journeyDraftMember(1);
    (new NotificationPreference)->forceFill([
        'user_id' => $optedIn->id, 'event' => NotificationEvent::JourneyWizardReminder->value,
        'in_app' => true, 'email' => true, 'sms' => true, 'push' => false,
    ])->save();

    $this->travelTo(CarbonImmutable::parse('2026-11-12 22:15', 'Asia/Kolkata'));
    runJourneys();

    expect(remindersTo($default))->toBe(0)
        ->and(JourneySend::query()->where('user_id', $default->id)->sole()->status)->toBe(JourneySendStatus::SkippedOptedOut)
        ->and(remindersTo($optedIn))->toBe(1);
    Notification::assertSentTo($optedIn, JourneyReminder::class, function (JourneyReminder $n) use ($optedIn): bool {
        $delay = $n->withDelay($optedIn, SmsChannel::class)[SmsChannel::class] ?? null;

        return $n->via($optedIn) === [SmsChannel::class]
            && $delay !== null && CarbonImmutable::instance($delay)->setTimezone('Asia/Kolkata')->format('Y-m-d H:i') === '2026-11-13 07:00';
    });
});

it('A14 configurable, not code: an admin\'s new offsets and channel are used by the next run, without a deploy (audited)', function (): void {
    $admin = adminWithRole();
    $member = journeyDraftMember(2);
    runJourneys();
    expect(remindersTo($member))->toBe(0);   // off

    app(SaveJourney::class)->handle($admin, JourneyKey::WizardIncomplete, true, ['5', 2], 'in_app', 'Nudge earlier this month');
    runJourneys();

    Notification::assertSentTo($member, JourneyReminder::class, fn (JourneyReminder $n): bool => $n->via($member) === [App\Notifications\Support\InAppChannel::class]);
    $journey = AutomatedJourney::query()->where('key', 'wizard_incomplete')->sole();
    expect($journey->offsets)->toBe([2, 5])
        ->and(App\Models\AuditLog::query()->where('action', 'comms.journey_updated')->sole())
        ->reason->toBe('Nudge earlier this month')
        ->before->toMatchArray(['is_active' => false, 'offsets' => [1, 3, 7], 'channel' => 'email'])
        ->after->toMatchArray(['is_active' => true, 'offsets' => [2, 5], 'channel' => 'in_app']);
});

it('A14 SaveJourney security: campaigns.send required; invalid offsets, a channel the reminder never uses, or no reason → refused, nothing changed', function (Closure $call, string $exception): void {
    expect($call)->toThrow($exception);
    expect(AutomatedJourney::query()->where('key', 'wizard_incomplete')->sole())
        ->is_active->toBeFalse()->offsets->toBe([1, 3, 7]);
})->with([
    'view-only admin (content editor)' => [fn () => app(SaveJourney::class)->handle(adminWithRole('content_editor'), JourneyKey::WizardIncomplete, true, [1], 'email', 'Valid reason'), AuthorizationException::class],
    'no offsets' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [], 'email', 'Valid reason'), ValidationException::class],
    'day 0' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [0], 'email', 'Valid reason'), ValidationException::class],
    'day 366' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [366], 'email', 'Valid reason'), ValidationException::class],
    'repeated day' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [3, 3], 'email', 'Valid reason'), ValidationException::class],
    'four days' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [1, 2, 3, 4], 'email', 'Valid reason'), ValidationException::class],
    'not a number' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, ['3 days'], 'email', 'Valid reason'), ValidationException::class],
    'unknown channel' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [1], 'push', 'Valid reason'), ValidationException::class],
    'no reason' => [fn () => app(SaveJourney::class)->handle(adminWithRole(), JourneyKey::WizardIncomplete, true, [1], 'email', '  '), ValidationException::class],
]);

it('A14 journeys: purging a member removes their journey ledger rows', function (): void {
    journeyOn(JourneyKey::WizardIncomplete, [1]);
    $member = journeyDraftMember(1);
    runJourneys();
    expect(JourneySend::query()->where('user_id', $member->id)->count())->toBe(1);

    $member->delete();
    $this->travel(App\Support\Facades\Settings::int(SettingKey::MembersPurgeAfterDays) + 1)->days();
    app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($member->id));

    expect(JourneySend::query()->where('user_id', $member->id)->exists())->toBeFalse();
});

it('A14 journeys: ledger rows older than journeys.ledger_retention_days (A15, 400 by default) are pruned by the daily run', function (): void {
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle(adminWithRole(), ['journeys.ledger_retention_days' => 380], 'Shorter');
    $member = campaignMember();
    JourneySend::query()->insert([
        ['journey_key' => 'win_back', 'user_id' => $member->id, 'step' => 30, 'subject_ref' => 'old', 'sent_on' => '2025-01-01', 'status' => 'SENT', 'created_at' => now()->subDays(381)],
        ['journey_key' => 'win_back', 'user_id' => $member->id, 'step' => 30, 'subject_ref' => 'new', 'sent_on' => '2026-10-01', 'status' => 'SENT', 'created_at' => now()->subDays(379)],
    ]);

    runJourneys();

    expect(JourneySend::query()->pluck('subject_ref')->all())->toBe(['new']);
});

it('A14 journeys run daily at 10:30 IST, after the plan-expiry reminders', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->description, 'RunJourneys'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 10 * * *')
        ->and($event->timezone)->toBe('Asia/Kolkata');
});

it('A14 journey events are in the member\'s /settings matrix, SMS off until switched on', function (): void {
    foreach ([NotificationEvent::JourneyInterestReminder, NotificationEvent::JourneyWizardReminder, NotificationEvent::JourneyNewMatches, NotificationEvent::JourneyWinBack] as $event) {
        expect(in_array($event, NotificationEvent::forMemberSettings(), true))->toBeTrue()
            ->and($event->isMandatory())->toBeFalse()
            ->and($event->usesChannel('sms'))->toBeTrue()
            ->and($event->defaultFor('sms'))->toBeFalse()
            ->and($event->defaultFor('email'))->toBeTrue();
    }
});
