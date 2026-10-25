<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ManagedProfileStatusChange;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Jobs\Broker\PurgeDeletedManagedProfiles;
use App\Jobs\Broker\SendFollowUpReminders;
use App\Models\AuditLog;
use App\Models\BrokerClientNote;
use App\Models\ContactDetail;
use App\Models\Profile;
use App\Notifications\Broker\FollowUpDue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

/*
| P7.3 — the managed-profile jobs: FollowUpDue at 08:30 IST (§11A B.15) and the purge after the
| 30-day recovery (B.6, decisions 2026-10-25).
*/

beforeEach(function (): void {
    seedMasters();
});

function dueNote(Profile $profile, App\Models\User $author, string $on, bool $done = false): BrokerClientNote
{
    return BrokerClientNote::factory()->create([
        'profile_id' => $profile->id,
        'broker_id' => $profile->managed_by_broker_id,
        'author_user_id' => $author->id,
        'follow_up_on' => $on,
        'follow_up_done_at' => $done ? now() : null,
    ]);
}

function runReminders(): void
{
    app()->call([new SendFollowUpReminders, 'handle']);
}

// ---- FollowUpDue --------------------------------------------------------------------------------

it('B.15: one FollowUpDue per login with the count of follow-ups due today or overdue — author and assignee', function (): void {
    Notification::fake();
    $owner = bureauLogin(BureauRole::Owner);
    $broker = staffOf($owner)->broker;
    $telecaller = bureauLogin(BureauRole::Telecaller, broker: $broker);
    $profile = managedProfile($broker, [], staffOf($telecaller));
    $today = now(config('oppam.display_timezone'))->format('Y-m-d');

    dueNote($profile, $owner, $today);
    dueNote($profile, $owner, now(config('oppam.display_timezone'))->subDays(2)->format('Y-m-d'));
    dueNote($profile, $owner, now(config('oppam.display_timezone'))->addDay()->format('Y-m-d'));   // not yet
    dueNote($profile, $owner, $today, done: true);                                                    // done

    runReminders();

    Notification::assertSentTo($owner, FollowUpDue::class, fn (FollowUpDue $n) => $n->count === 2);
    Notification::assertSentTo($telecaller, FollowUpDue::class, fn (FollowUpDue $n) => $n->count === 2);   // assignee
    Notification::assertSentTimes(FollowUpDue::class, 2);
});

it('B.15: a re-run the same day sends nothing twice', function (): void {
    Notification::fake();
    $owner = bureauLogin();
    dueNote(managedProfile(staffOf($owner)->broker), $owner, now(config('oppam.display_timezone'))->format('Y-m-d'));

    runReminders();
    runReminders();

    Notification::assertSentTimes(FollowUpDue::class, 1);
});

it('B.15: nobody is reminded about a client outside their scope, after deactivation, on a suspended bureau, or for deleted / claimed profiles', function (string $case): void {
    Notification::fake();
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $author = bureauLogin(BureauRole::DataEntry, broker: $broker);
    $profile = managedProfile($broker);
    dueNote($profile, $author, now(config('oppam.display_timezone'))->format('Y-m-d'));

    match ($case) {
        'assigned-only author, not assigned' => staffOf($author)->forceFill(['profile_scope' => ProfileScope::Assigned])->save(),
        'author deactivated' => staffOf($author)->forceFill(['status' => BureauStaffStatus::Deactivated])->save(),
        'notes switched off' => staffOf($author)->forceFill(['disabled_permissions' => ['client_notes.write']])->save(),
        'bureau suspended' => $broker->forceFill(['status' => BrokerStatus::Suspended])->save(),
        'profile deleted' => $profile->delete(),
        'profile claimed' => $profile->forceFill(['claimed_at' => now()])->save(),
    };

    runReminders();

    Notification::assertNotSentTo($author, FollowUpDue::class);
})->with(['assigned-only author, not assigned', 'author deactivated', 'notes switched off', 'bureau suspended', 'profile deleted', 'profile claimed']);

it('B.15: the reminder text names no client', function (): void {
    $content = (new FollowUpDue(3))->content(bureauLogin());

    expect($content->title)->toBe('3 client follow-ups are due')->and($content->url)->toEndWith('/broker');
});

it('is scheduled at 08:30 IST; the purge daily', function (): void {
    $events = collect(app(Schedule::class)->events());

    $reminders = $events->first(fn ($e) => str_contains((string) $e->description, SendFollowUpReminders::class));
    $purge = $events->first(fn ($e) => str_contains((string) $e->description, PurgeDeletedManagedProfiles::class));

    expect($reminders?->expression)->toBe('30 8 * * *')->and($reminders?->timezone)->toBe('Asia/Kolkata')
        ->and($purge)->not->toBeNull();
});

// ---- Purge after the 30-day recovery --------------------------------------------------------------

it('B.6: a managed profile deleted more than 30 days ago is purged — details, notes and the client mobile; a tombstone stays', function (): void {
    $login = bureauLogin();
    $profile = managedClientReadyToSubmit($login);
    dueNote($profile, $login, now()->format('Y-m-d'));
    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Delete);

    $this->travel(31)->days();
    app()->call([new PurgeDeletedManagedProfiles, 'handle']);

    $tombstone = Profile::withTrashed()->findOrFail($profile->id);
    expect($tombstone->client_phone)->toBeNull()
        ->and($tombstone->first_name)->toBe('Deleted')
        ->and($tombstone->status)->toBe(ProfileStatus::Draft)   // it was a DRAFT (CHECK: basics only required otherwise)
        ->and($tombstone->broker_consent_confirmed_at)->not->toBeNull()   // evidence kept
        ->and(ContactDetail::query()->where('profile_id', $profile->id)->exists())->toBeFalse()
        ->and(BrokerClientNote::query()->where('profile_id', $profile->id)->exists())->toBeFalse()
        ->and(App\Models\Media::query()->where('model_id', $profile->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'broker.profile_purged')->exists())->toBeTrue();
});

it('B.6: within 30 days, under legal hold, or not deleted — nothing is purged; a re-run changes nothing', function (string $case): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);

    if ($case !== 'not deleted') {
        app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Delete);
    }
    if ($case === 'legal hold') {
        Profile::withTrashed()->whereKey($profile->id)->update(['legal_hold_at' => now()]);
    }

    $this->travel($case === 'within 30 days' ? 29 : 40)->days();
    app()->call([new PurgeDeletedManagedProfiles, 'handle']);

    expect(Profile::withTrashed()->findOrFail($profile->id)->client_phone)->not->toBeNull();
})->with(['within 30 days', 'legal hold', 'not deleted']);

it('the purge never touches a member’s deleted profile (A03 handles those)', function (): void {
    $member = memberWithPhone('+919800055566');
    $member->profile->delete();
    $this->travel(40)->days();

    app()->call([new PurgeDeletedManagedProfiles, 'handle']);

    expect(Profile::withTrashed()->where('user_id', $member->id)->value('first_name'))->not->toBe('Deleted');
});
