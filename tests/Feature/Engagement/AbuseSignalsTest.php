<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\DecideEngagementSignal;
use App\Actions\Admin\Chat\LiftRestriction;
use App\Actions\Admin\Members\PurgeDeletedMember;
use App\Actions\Chat\SendMessage;
use App\Actions\Engagement\RequestAccess;
use App\Actions\Engagement\SendInterest;
use App\Actions\Engagement\SendInterests;
use App\Actions\Engagement\ToggleFavorite;
use App\Actions\Engagement\ToggleLike;
use App\Domain\Engagement\EngagementRestrictions;
use App\Domain\Engagement\EngagementSignals;
use App\Enums\AccessRequestType;
use App\Enums\EngagementSignalDecision;
use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Enums\InterestStatus;
use App\Enums\PlanCode;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Exceptions\Admin\MemberStateConflict;
use App\Exceptions\Chat\ChatRefused;
use App\Exceptions\Engagement\EngagementRefused;
use App\Exceptions\Engagement\SignalUnavailable;
use App\Jobs\Engagement\DetectEngagementSignals;
use App\Models\AuditLog;
use App\Models\ConversationParticipant;
use App\Models\EngagementSignal;
use App\Models\Interest;
use App\Models\MemberRestriction;
use App\Models\Profile;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\Engagement\EngagementWarning;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/*
| P4.3b — A13 engagement abuse monitors (PRD A13): signals raised as things happen (favourite spam,
| repeated messages, one-sided messaging) and by the hourly detector (interest spam, new accounts
| messaging many members); one open signal per member and kind; one-click decisions (pause sending
| — every outgoing action refused, decisions 2026-10-18 — warn, suspend, dismiss), each with its own
| permission and audited.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
});

function signalSetting(SettingKey $key, int $value): void
{
    (new Setting)->forceFill(['key' => $key->value, 'value' => $value])->save();
    Settings::flush();
}

function signalOf(User $member, EngagementSignalType $type): ?EngagementSignal
{
    return EngagementSignal::query()->where('profile_id', $member->profile->id)->where('type', $type->value)->first();
}

function signalDecide(EngagementSignal $signal, EngagementSignalDecision $decision, ?int $hours = null, ?string $note = null, string $role = 'moderator'): void
{
    app(DecideEngagementSignal::class)->handle(adminWithRole($role), $signal->refresh(), $decision, $hours, $note);
}

it('one OPEN signal per member and kind: a repeat counts occurrences instead of a new row; the badge follows after commit', function (): void {
    Event::fake([AdminQueueCountChanged::class]);
    $member = groom();
    $signals = app(EngagementSignals::class);

    $signals->raise($member->profile->id, EngagementSignalType::LikeSpam, ['likes' => 50, 'window_minutes' => 10]);
    $signals->raise($member->profile->id, EngagementSignalType::LikeSpam, ['likes' => 50, 'window_minutes' => 10]);

    $signal = signalOf($member, EngagementSignalType::LikeSpam);
    expect(EngagementSignal::query()->count())->toBe(1)->and($signal->occurrences)->toBe(2)->and($signal->status)->toBe(EngagementSignalStatus::Open);
    Event::assertDispatched(AdminQueueCountChanged::class, fn ($e): bool => $e->queueKey === EngagementSignal::QUEUE_KEY && $e->count === 1);

    signalDecide($signal, EngagementSignalDecision::Dismiss);
    $signals->raise($member->profile->id, EngagementSignalType::LikeSpam, ['likes' => 50, 'window_minutes' => 10]);
    expect(EngagementSignal::query()->count())->toBe(2);   // a new episode after the decision
});

it('A13 "mass favourites via automation": hitting the favourites limit raises a signal', function (): void {
    signalSetting(SettingKey::FavoritesMaxPerMinute, 5);
    $member = groom();
    $targets = Profile::factory()->female()->active()->count(6)->create();

    foreach ($targets->take(5) as $target) {
        app(ToggleFavorite::class)->handle($member, $target, true);
    }
    expect(fn () => app(ToggleFavorite::class)->handle($member, $targets->last(), true))->toThrow(EngagementRefused::class);

    expect(signalOf($member, EngagementSignalType::FavoriteSpam)?->details)->toBe(['changes_per_minute' => 5]);
});

it('A13 repeated identical messages: a REPETITION flag also raises a signal on the sender', function (): void {
    $sender = groom();
    $spam = 'Hello dear, I liked your profile very much, please reply soon';
    foreach (['+919800000901', '+919800000902', '+919800000903'] as $i => $phone) {
        [$sender, $member] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender, $member, $spam);
    }

    expect(signalOf($sender, EngagementSignalType::MessageRepetition)?->details)->toMatchArray(['threads' => 3]);
});

it('A13 one-sided messaging: chat.one_sided_threshold messages in a row without a reply raise ONE signal; a reply resets the streak', function (): void {
    signalSetting(SettingKey::ChatOneSidedThreshold, 5);
    signalSetting(SettingKey::ChatMaxPerMinute, 100);
    [$groom, $bride, $thread] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $streak = fn (User $member): int => (int) ConversationParticipant::query()->where('conversation_id', $thread->id)->where('profile_id', $member->profile->id)->value('unanswered_count');

    foreach (range(1, 4) as $i) {
        chatSend($groom, $bride, 'Hello '.$i);
    }
    expect(signalOf($groom, EngagementSignalType::OneSidedChat))->toBeNull()->and($streak($groom))->toBe(4);

    chatSend($groom, $bride, 'Hello 5');
    chatSend($groom, $bride, 'Hello 6');
    expect(signalOf($groom, EngagementSignalType::OneSidedChat)?->occurrences)->toBe(1);

    chatSend($bride, $groom, 'Please stop');
    expect($streak($groom))->toBe(0)->and($streak($bride))->toBe(1);
});

it('A13 interest spam (hourly): many interests, almost none accepted → a signal; not raised again inside the window', function (): void {
    signalSetting(SettingKey::SignalsInterestSpamMinSent, 5);
    $spammer = groom();
    $honest = groom('+919800000911');
    foreach (Profile::factory()->female()->active()->count(6)->create() as $i => $target) {
        Interest::factory()->create(['from_profile_id' => $spammer->profile->id, 'to_profile_id' => $target->id, 'status' => InterestStatus::Pending, 'sent_at' => now()->subDays(2)]);
        Interest::factory()->create(['from_profile_id' => $honest->profile->id, 'to_profile_id' => $target->id,
            'status' => $i < 3 ? InterestStatus::Accepted : InterestStatus::Pending, 'sent_at' => now()->subDays(2)]);
    }

    (new DetectEngagementSignals)->handle(app(EngagementSignals::class));
    (new DetectEngagementSignals)->handle(app(EngagementSignals::class));

    expect(signalOf($spammer, EngagementSignalType::InterestSpam)?->details)->toMatchArray(['sent' => 6, 'accepted' => 0])
        ->and(signalOf($spammer, EngagementSignalType::InterestSpam)?->occurrences)->toBe(1)
        ->and(signalOf($honest, EngagementSignalType::InterestSpam))->toBeNull();

    signalDecide(signalOf($spammer, EngagementSignalType::InterestSpam), EngagementSignalDecision::Dismiss);
    (new DetectEngagementSignals)->handle(app(EngagementSignals::class));
    expect(EngagementSignal::query()->where('type', EngagementSignalType::InterestSpam->value)->count())->toBe(1);   // dismissed stays dismissed in the window
});

it('A13 new accounts messaging many members (hourly): a young account writing in many conversations in 24 h', function (): void {
    signalSetting(SettingKey::SignalsNewAccountThreadsPerDay, 3);
    $sender = groom();
    foreach (['+919800000921', '+919800000922', '+919800000923'] as $i => $phone) {
        [$sender, $member] = chatPairOf($sender->refresh(), bride($phone), $i === 0 ? PlanCode::Gold : null);
        chatSend($sender, $member, 'Hi there '.$i);
    }
    User::query()->whereKey($sender->id)->update(['created_at' => now()->subDays(30)]);
    (new DetectEngagementSignals)->handle(app(EngagementSignals::class));
    expect(signalOf($sender, EngagementSignalType::NewAccountMassMessaging))->toBeNull();

    User::query()->whereKey($sender->id)->update(['created_at' => now()->subDays(2)]);
    (new DetectEngagementSignals)->handle(app(EngagementSignals::class));
    expect(signalOf($sender, EngagementSignalType::NewAccountMassMessaging)?->details)->toBe(['threads_24h' => 3]);
});

it('decisions 2026-10-18: "pause sending" refuses likes, favourites, interests, requests and chat — taking things back still works', function (): void {
    [$groom, $bride] = chatConnected(PlanCode::Gold, PlanCode::Gold);
    $target = Profile::factory()->female()->active()->create();
    app(ToggleFavorite::class)->handle($groom, $target, true);
    $signal = EngagementSignal::factory()->on($groom->profile)->create();

    signalDecide($signal, EngagementSignalDecision::RateLimit, 24, 'Like spam confirmed');
    app(EngagementRestrictions::class)->forget($groom->profile);

    expect(fn () => app(ToggleLike::class)->handle($groom, $target, true))->toThrow(EngagementRefused::class, 'paused')
        ->and(fn () => app(ToggleFavorite::class)->handle($groom, Profile::factory()->female()->active()->create(), true))->toThrow(EngagementRefused::class)
        ->and(fn () => app(SendInterest::class)->handle($groom, $target, 'T01'))->toThrow(EngagementRefused::class)
        ->and(fn () => app(SendInterests::class)->handle($groom, [$target->code], 'T01'))->toThrow(EngagementRefused::class)
        ->and(fn () => app(RequestAccess::class)->handle($groom, $target, AccessRequestType::Photo))->toThrow(EngagementRefused::class)
        ->and(fn () => chatSend($groom, $bride))->toThrow(ChatRefused::class)
        ->and(fn () => app(SendMessage::class)->image($groom, $bride->profile, gradientPhoto(), (string) Str::uuid()))->toThrow(ChatRefused::class);

    expect(app(ToggleFavorite::class)->handle($groom, $target, false))->toBeFalse()   // removing still works
        ->and(chatSend($bride, $groom, 'The other member can still write')->body)->not->toBe('');

    $restriction = MemberRestriction::query()->sole();
    expect($restriction->until->diffInHours(now()->addDay(), true))->toBeLessThan(1)
        ->and($restriction->signal_id)->toBe($signal->id)
        ->and(AuditLog::query()->where('action', 'chat.signal.decided')->sole()->after)->toMatchArray(['decision' => 'RATE_LIMIT']);

    try {
        chatSend($groom, $bride);
    } catch (ChatRefused $refused) {
        expect($refused->reason)->toBe(ChatRefused::PAUSED)->and($refused->getStatusCode())->toBe(403)->and($refused->getMessage())->toContain('paused');
    }
});

it('a running pause is never shortened; lifting it needs chat.moderate and a reason, and lets the member send again', function (): void {
    [$groom, $bride] = chatConnected();
    signalDecide(EngagementSignal::factory()->on($groom->profile)->create(), EngagementSignalDecision::RateLimit, 168);
    signalDecide(EngagementSignal::factory()->on($groom->profile, EngagementSignalType::OneSidedChat)->create(), EngagementSignalDecision::RateLimit, 1);
    expect(MemberRestriction::query()->count())->toBe(1);

    $restriction = MemberRestriction::query()->sole();
    $lift = app(LiftRestriction::class);
    expect(fn () => $lift->handle(adminWithRole('support'), $restriction, 'Reviewed with the member'))->toThrow(AuthorizationException::class)
        ->and(fn () => $lift->handle(adminWithRole('moderator'), $restriction, 'no'))->toThrow(ValidationException::class);

    $lift->handle(adminWithRole('moderator'), $restriction, 'Reviewed with the member');
    app(EngagementRestrictions::class)->forget($groom->profile);

    expect(chatSend($groom, $bride)->body)->toBe('Hello there')
        ->and(fn () => $lift->handle(adminWithRole('moderator'), $restriction->refresh(), 'Reviewed with the member'))->toThrow(SignalUnavailable::class)
        ->and(AuditLog::query()->where('action', 'chat.restriction.lifted')->count())->toBe(1);
});

it('warn sends the fixed-wording EngagementWarning; suspend runs the A03 flow and needs its reason', function (): void {
    $member = groom();
    signalDecide(EngagementSignal::factory()->on($member->profile)->create(), EngagementSignalDecision::Warn);
    Notification::assertSentTo($member, EngagementWarning::class);

    $signal = EngagementSignal::factory()->on($member->profile, EngagementSignalType::InterestSpam)->create();
    expect(fn () => signalDecide($signal, EngagementSignalDecision::Suspend, note: null, role: 'super_admin'))->toThrow(ValidationException::class);

    signalDecide($signal, EngagementSignalDecision::Suspend, note: 'Automated interest spam', role: 'super_admin');
    expect($member->refresh()->status)->toBe(UserStatus::Suspended)
        ->and($signal->refresh()->status)->toBe(EngagementSignalStatus::Actioned)
        ->and(AuditLog::query()->where('action', 'members.suspended')->count())->toBe(1);

    $again = EngagementSignal::factory()->on($member->profile, EngagementSignalType::LikeSpam)->create();
    expect(fn () => signalDecide($again, EngagementSignalDecision::Suspend, note: 'Automated interest spam', role: 'super_admin'))->toThrow(MemberStateConflict::class);
});

it('security: each decision needs its own permission; a decided signal can\'t be decided again; an unknown pause length is refused', function (): void {
    $member = groom();
    $signal = EngagementSignal::factory()->on($member->profile)->create();

    expect(fn () => signalDecide($signal, EngagementSignalDecision::Dismiss, role: 'support'))->toThrow(AuthorizationException::class)
        ->and(fn () => signalDecide($signal, EngagementSignalDecision::Suspend, note: 'Automated spam', role: 'moderator'))->toThrow(AuthorizationException::class)
        ->and(fn () => signalDecide($signal, EngagementSignalDecision::RateLimit, 5))->toThrow(ValidationException::class)
        ->and(fn () => signalDecide($signal, EngagementSignalDecision::Dismiss, note: str_repeat('x', 501)))->toThrow(ValidationException::class);

    signalDecide($signal, EngagementSignalDecision::Dismiss);
    expect(fn () => signalDecide($signal, EngagementSignalDecision::Warn))->toThrow(SignalUnavailable::class)
        ->and(MemberRestriction::query()->count())->toBe(0);
});

it('purge removes the member\'s pauses and signals (A03 / P1.7a rule)', function (): void {
    $member = groom();
    signalDecide(EngagementSignal::factory()->on($member->profile)->create(), EngagementSignalDecision::RateLimit, 24);
    app(App\Actions\Admin\Members\DeleteMember::class)->handle(adminWithRole(), $member, $member->profile->code, 'Member asked us to delete it.');
    $this->travel(31)->days();

    app(PurgeDeletedMember::class)->handle(User::withTrashed()->findOrFail($member->id));

    expect(MemberRestriction::query()->count())->toBe(0)->and(EngagementSignal::query()->count())->toBe(0);
});
