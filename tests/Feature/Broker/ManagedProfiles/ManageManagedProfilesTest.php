<?php

declare(strict_types=1);

use App\Actions\Broker\ManagedProfiles\AddClientNote;
use App\Actions\Broker\ManagedProfiles\AssignManagedProfiles;
use App\Actions\Broker\ManagedProfiles\CompleteFollowUp;
use App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus;
use App\Actions\Broker\ManagedProfiles\SubmitManagedProfile;
use App\Actions\Broker\ManagedProfiles\UpdateManagedProfile;
use App\Actions\Moderation\ApproveProfile;
use App\Actions\Moderation\RejectProfile;
use App\Actions\Profile\SaveBasicDetails;
use App\Data\Broker\ClientNoteData;
use App\Domain\Moderation\ModerationFlags;
use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ManagedProfileStatusChange;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileScope;
use App\Enums\ProfileStatus;
use App\Enums\RejectReason;
use App\Events\Broker\ManagedProfileModerated;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerClientNote;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Broker\ManagedProfileReviewed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.3 — managing a bureau's client profiles (§11A B.6 / B.15): submit → A04 (R-M13-14, the
| "Added by broker" flag), the moderation decision back to the bureau (live + notification to the
| right people), status actions with their B.2 permissions, assignment, the client mobile, notes.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
});

function sameBureau(App\Models\User $login, BureauRole $role, ProfileScope $scope = ProfileScope::All): App\Models\User
{
    $other = bureauLogin($role, broker: staffOf($login)->broker);
    staffOf($other)->forceFill(['profile_scope' => $scope])->save();

    return $other->refresh();
}

// ---- Submit → A04 → back to the bureau -----------------------------------------------------------

it('R-M13-14: a managed profile goes through the A04 queue like any profile, flagged "Added by broker"', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $profile = managedClientReadyToSubmit($login);

    $item = app(SubmitManagedProfile::class)->handle($login, $profile->code);

    expect($profile->refresh()->status)->toBe(ProfileStatus::PendingReview)
        ->and($item->type)->toBe(ModerationItemType::ProfileNew)
        ->and($item->status)->toBe(ModerationStatus::Open)
        ->and($profile->published_at)->toBeNull();

    $flags = collect(app(ModerationFlags::class)->forProfile($profile, []))->pluck('message', 'code');
    expect($flags['broker_managed'] ?? null)->toBe('Added by broker '.staffOf($login)->broker->code.'.');

    expect(AuditLog::query()->where('action', 'broker.profile_submitted')->exists())->toBeTrue();
});

it('a member’s own profile never carries the broker flag', function (): void {
    $member = memberThroughStep(6);

    expect(collect(app(ModerationFlags::class)->forProfile($member->profile, []))->pluck('code'))->not->toContain('broker_managed');
});

it('an incomplete managed draft can’t be submitted', function (): void {
    $login = bureauLogin();
    $profile = app(App\Actions\Broker\ManagedProfiles\CreateManagedProfile::class)->handle($login, managedData(), basicData());

    expect(fn () => app(SubmitManagedProfile::class)->handle($login, $profile->code))->toThrow(ProfileNotSubmittable::class);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Draft);
});

it('B.2: a Telecaller can’t submit; an Owner switch-off removes it', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $profile = managedClientReadyToSubmit($login);

    expect(fn () => app(SubmitManagedProfile::class)->handle(sameBureau($login, BureauRole::Telecaller), $profile->code))->toThrow(HttpException::class);

    $entry = bureauLogin(BureauRole::DataEntry, broker: staffOf($login)->broker, disabled: ['profiles.submit_for_review']);
    expect(fn () => app(SubmitManagedProfile::class)->handle($entry, $profile->code))->toThrow(HttpException::class);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Draft);
});

it('B.15: approval → ACTIVE + searchable, `profile.moderated` on broker.{id} with code + decision only, and the right people are told', function (): void {
    Event::fake([ManagedProfileModerated::class]);
    Notification::fake();
    $owner = bureauLogin(BureauRole::Owner);
    $broker = staffOf($owner)->broker;
    $creator = sameBureau($owner, BureauRole::DataEntry);
    $manager = sameBureau($owner, BureauRole::Manager);
    $telecaller = sameBureau($owner, BureauRole::Telecaller);
    $otherBureauOwner = bureauLogin(BureauRole::Owner);

    $profile = managedClientReadyToSubmit($creator);
    $item = app(SubmitManagedProfile::class)->handle($creator, $profile->code);
    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    expect($profile->refresh()->status)->toBe(ProfileStatus::Active)
        ->and(Profile::query()->searchable()->whereKey($profile->id)->exists())->toBeTrue();

    Event::assertDispatched(ManagedProfileModerated::class, function (ManagedProfileModerated $event) use ($broker, $profile): bool {
        return $event->broadcastOn()[0]->name === 'private-broker.'.$broker->id
            && $event->broadcastAs() === 'profile.moderated'
            && $event->broadcastWith() === ['profile' => $profile->code, 'decision' => 'approved'];
    });

    foreach ([$owner, $manager, $creator] as $told) {
        Notification::assertSentTo($told, ManagedProfileReviewed::class, fn ($n) => $n->approved && $n->profileCode === $profile->code);
    }
    Notification::assertNotSentTo($telecaller, ManagedProfileReviewed::class);   // neither creator nor assignee
    Notification::assertNotSentTo($otherBureauOwner, ManagedProfileReviewed::class);
});

it('B.6: rejection → the reason reaches the bureau, which edits and resubmits', function (): void {
    Notification::fake();
    $owner = bureauLogin(BureauRole::Owner);
    $profile = managedClientReadyToSubmit($owner);
    $item = app(SubmitManagedProfile::class)->handle($owner, $profile->code);

    app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::ContactInText, 'Remove the number from About me.');

    expect($profile->refresh()->status)->toBe(ProfileStatus::Rejected);
    Notification::assertSentTo($owner, ManagedProfileReviewed::class,
        fn ($n) => ! $n->approved && str_contains((string) $n->reason, 'Remove the number from About me.'));

    // Edit & resubmit through the same Actions.
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($owner, $profile, aboutData(['about' => str_repeat('A kind and caring person who loves her family. ', 2)]));
    app(SubmitManagedProfile::class)->handle($owner, $profile->code);
    expect($profile->refresh()->status)->toBe(ProfileStatus::PendingReview);
});

it('B.15: an assigned-only creator who was re-assigned away hears nothing', function (): void {
    Notification::fake();
    $owner = bureauLogin(BureauRole::Owner);
    $creator = sameBureau($owner, BureauRole::DataEntry, ProfileScope::Assigned);
    $profile = managedClientReadyToSubmit($creator);
    $item = app(SubmitManagedProfile::class)->handle($creator, $profile->code);

    app(AssignManagedProfiles::class)->handle($owner, [$profile->code], null);
    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    Notification::assertNotSentTo($creator, ManagedProfileReviewed::class);
    Notification::assertSentTo($owner, ManagedProfileReviewed::class);
});

it('a member’s profile decision sends nothing to any bureau', function (): void {
    Event::fake([ManagedProfileModerated::class]);
    $member = memberThroughStep(6);
    $item = app(App\Actions\Profile\SubmitProfile::class)->handle($member, $member->profile);

    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    Event::assertNotDispatched(ManagedProfileModerated::class);
});

// ---- The wizard Actions through the bureau branch of ProfilePolicy ---------------------------------

it('B.17: the bureau edits a draft’s sections with the M02 Actions — and their rules', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);

    app(SaveBasicDetails::class)->handle($login, $profile, basicData(['first_name' => 'Meera']));
    expect($profile->refresh()->first_name)->toBe('Meera');

    expect(fn () => app(SaveBasicDetails::class)->handle($login, $profile, basicData(['dob' => now()->subYears(16)->format('Y-m-d')])))
        ->toThrow(ValidationException::class);
});

it('R-M02-4: on a live managed profile, changed names wait for review exactly as for a member', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $profile = managedProfile(staffOf($login)->broker, ['first_name' => 'Anjali', 'last_name' => 'Nair']);

    app(SaveBasicDetails::class)->handle($login, $profile, basicData(['first_name' => 'Meera', 'gender' => $profile->gender->value,
        'dob' => $profile->dob?->format('Y-m-d'), 'religion_id' => $profile->religion_id, 'caste_id' => $profile->caste_id,
        'marital_status' => $profile->marital_status?->value]));

    expect($profile->refresh()->first_name)->toBe('Anjali')
        ->and(ModerationItem::query()->where('profile_id', $profile->id)->where('type', ModerationItemType::ProfileEdit)->exists())->toBeTrue();
});

it('R-M13-6: the M02 Actions refuse another bureau’s profile, a Telecaller and a member', function (): void {
    $login = bureauLogin(BureauRole::Owner);
    $profile = managedProfile(staffOf($login)->broker, ['status' => ProfileStatus::Draft, 'published_at' => null]);

    foreach ([bureauLogin(BureauRole::Owner), sameBureau($login, BureauRole::Telecaller), bride()] as $intruder) {
        expect(fn () => app(SaveBasicDetails::class)->handle($intruder, $profile, basicData(['first_name' => 'Hacked'])))
            ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
    }

    expect($profile->refresh()->first_name)->not->toBe('Hacked');
});

// ---- Status actions -----------------------------------------------------------------------------

it('B.6: deactivate (ACTIVE → HIDDEN, out of search) and activate again', function (): void {
    $login = bureauLogin(BureauRole::Manager);
    $profile = managedProfile(staffOf($login)->broker);

    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Deactivate);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Hidden)
        ->and(Profile::query()->searchable()->whereKey($profile->id)->exists())->toBeFalse();

    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Activate);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Active);

    expect(AuditLog::query()->whereIn('action', ['broker.profile_deactivate', 'broker.profile_activate'])->count())->toBe(2);
});

it('R-M13-14: a bureau can never publish a profile itself — activate refuses DRAFT, REJECTED and never-approved HIDDEN', function (array $state): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker, $state);

    expect(fn () => app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Activate))
        ->toThrow(ManagedProfileRefused::class);
    expect($profile->refresh()->status)->toBe($state['status']);
})->with([
    'draft' => [['status' => ProfileStatus::Draft, 'published_at' => null]],
    'rejected' => [['status' => ProfileStatus::Rejected, 'published_at' => null]],
    'pending' => [['status' => ProfileStatus::PendingReview, 'published_at' => null]],
    'hidden, never approved' => [['status' => ProfileStatus::Hidden, 'published_at' => null]],
]);

it('B.6: delete hides it from everyone; restore within 30 days brings back the status it had', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);

    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Delete);
    expect(Profile::query()->whereKey($profile->id)->exists())->toBeFalse()
        ->and(Profile::withTrashed()->findOrFail($profile->id)->status)->toBe(ProfileStatus::Deleted);

    $this->travel(29)->days();
    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Restore);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Active)->and($profile->trashed())->toBeFalse();
});

it('B.6: a deleted DRAFT stays a DRAFT (missing basics are allowed only there); a deleted pending profile comes back as DRAFT', function (): void {
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    $draft = managedProfile($broker, ['status' => ProfileStatus::Draft, 'published_at' => null, 'last_name' => null, 'dob' => null]);
    $pending = managedProfile($broker, ['status' => ProfileStatus::PendingReview, 'published_at' => null]);

    app(SetManagedProfileStatus::class)->handle($login, $draft->code, ManagedProfileStatusChange::Delete);
    app(SetManagedProfileStatus::class)->handle($login, $pending->code, ManagedProfileStatusChange::Delete);
    expect(Profile::withTrashed()->findOrFail($draft->id)->status)->toBe(ProfileStatus::Draft);

    app(SetManagedProfileStatus::class)->handle($login, $pending->code, ManagedProfileStatusChange::Restore);
    expect($pending->refresh()->status)->toBe(ProfileStatus::Draft);
});

it('B.6: after 30 days a deleted profile can no longer be restored', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Delete);

    $this->travel(31)->days();

    expect(fn () => app(SetManagedProfileStatus::class)->handle($login, $profile->code, ManagedProfileStatusChange::Restore))
        ->toThrow(ManagedProfileRefused::class);
});

it('B.2: each status action needs its own permission', function (BureauRole $role, ManagedProfileStatusChange $change): void {
    $owner = bureauLogin();
    $profile = managedProfile(staffOf($owner)->broker);

    expect(fn () => app(SetManagedProfileStatus::class)->handle(sameBureau($owner, $role), $profile->code, $change))
        ->toThrow(HttpException::class);
    expect($profile->refresh()->status)->toBe(ProfileStatus::Active)->and($profile->trashed())->toBeFalse();
})->with([BureauRole::DataEntry, BureauRole::Telecaller])
    ->with([ManagedProfileStatusChange::Deactivate, ManagedProfileStatusChange::Delete]);

// ---- Assignment -----------------------------------------------------------------------------------

it('B.6: bulk assign to an active staff member, then clear', function (): void {
    $owner = bureauLogin();
    $broker = staffOf($owner)->broker;
    $telecaller = staffOf(sameBureau($owner, BureauRole::Telecaller));
    $a = managedProfile($broker);
    $b = managedProfile($broker);

    expect(app(AssignManagedProfiles::class)->handle($owner, [$a->code, $b->code, $a->code], $telecaller->id))->toBe(2);
    expect($a->refresh()->assigned_staff_id)->toBe($telecaller->id)->and($b->refresh()->assigned_staff_id)->toBe($telecaller->id);

    expect(app(AssignManagedProfiles::class)->handle($owner, [$a->code], null))->toBe(1);
    expect($a->refresh()->assigned_staff_id)->toBeNull();
});

it('R-M13-6: any foreign code rejects the whole assignment (404), nothing changes', function (): void {
    $owner = bureauLogin();
    $mine = managedProfile(staffOf($owner)->broker);
    $foreign = managedProfile(Broker::factory()->create());
    $staff = staffOf(sameBureau($owner, BureauRole::Telecaller));

    expect(fn () => app(AssignManagedProfiles::class)->handle($owner, [$mine->code, $foreign->code], $staff->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($mine->refresh()->assigned_staff_id)->toBeNull()->and($foreign->refresh()->assigned_staff_id)->toBeNull();
});

it('the assignee must be an ACTIVE login of the same bureau', function (string $case): void {
    $owner = bureauLogin();
    $profile = managedProfile(staffOf($owner)->broker);
    $assignee = match ($case) {
        'other bureau' => staffOf(bureauLogin(BureauRole::Telecaller)),
        'deactivated' => tap(staffOf(sameBureau($owner, BureauRole::Telecaller)))->forceFill(['status' => BureauStaffStatus::Deactivated])->save(),
    };
    $assigneeId = $case === 'deactivated' ? App\Models\BrokerStaff::query()->where('status', BureauStaffStatus::Deactivated)->value('id') : $assignee->id;

    expect(fn () => app(AssignManagedProfiles::class)->handle($owner, [$profile->code], $assigneeId))->toThrow(ManagedProfileRefused::class);
    expect($profile->refresh()->assigned_staff_id)->toBeNull();
})->with(['other bureau', 'deactivated']);

it('B.2: DataEntry and Telecaller can’t assign; more than 100 codes is invalid', function (): void {
    $owner = bureauLogin();
    $profile = managedProfile(staffOf($owner)->broker);

    expect(fn () => app(AssignManagedProfiles::class)->handle(sameBureau($owner, BureauRole::DataEntry), [$profile->code], null))->toThrow(HttpException::class);
    expect(fn () => app(AssignManagedProfiles::class)->handle($owner, array_map(fn ($i) => 'OPM'.$i, range(1, 101)), null))->toThrow(ValidationException::class);
});

// ---- Client mobile ---------------------------------------------------------------------------------

it('B.6: the client mobile can be changed, with the same uniqueness rules; the audit row is masked', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    memberWithPhone('+919800011122');

    expect(fn () => app(UpdateManagedProfile::class)->handle($login, $profile->code, '9800011122'))->toThrow(ValidationException::class);

    app(UpdateManagedProfile::class)->handle($login, $profile->code, '98000 33344');
    expect($profile->refresh()->client_phone)->toBe('+919800033344');

    $audit = AuditLog::query()->where('action', 'broker.profile_client_phone_changed')->firstOrFail();
    expect(json_encode($audit->after))->not->toContain('9800033344');
});

// ---- Notes & follow-ups ---------------------------------------------------------------------------

it('B.6: a note / call / meeting with a follow-up; marking it done; Telecallers may write notes', function (): void {
    $owner = bureauLogin();
    $telecaller = sameBureau($owner, BureauRole::Telecaller);
    $profile = managedProfile(staffOf($owner)->broker);
    $tomorrow = now(config('oppam.display_timezone'))->addDay()->format('Y-m-d');

    $note = app(AddClientNote::class)->handle($telecaller, $profile->code, new ClientNoteData('CALL', 'Family interested', 'Spoke to the father.', $tomorrow));

    expect($note->broker_id)->toBe(staffOf($owner)->broker_id)
        ->and($note->author_user_id)->toBe($telecaller->id)
        ->and($note->follow_up_on?->format('Y-m-d'))->toBe($tomorrow);

    app(CompleteFollowUp::class)->handle($owner, $note->id);
    expect($note->refresh()->follow_up_done_at)->not->toBeNull();
});

it('notes: invalid input is refused, nothing written', function (array $data, string $field): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);

    try {
        app(AddClientNote::class)->handle($login, $profile->code, new ClientNoteData(...$data));
        $this->fail('expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(BrokerClientNote::query()->count())->toBe(0);
})->with([
    'unknown type' => [['SMS', null, 'Hello there', null], 'type'],
    'empty body' => [['NOTE', null, '', null], 'body'],
    'too long' => [['NOTE', null, str_repeat('a', 2001), null], 'body'],
    'follow-up in the past' => [fn () => ['NOTE', null, 'Call back', now(config('oppam.display_timezone'))->subDay()->format('Y-m-d')], 'follow_up_on'],
    'bad date' => [['NOTE', null, 'Call back', '31/12/2026'], 'follow_up_on'],
]);

it('notes are stored as typed and escaped on output (no HTML executes)', function (): void {
    $login = actingAsBureau(bureauLogin());
    $profile = managedProfile(staffOf($login)->broker);
    app(AddClientNote::class)->handle($login, $profile->code, new ClientNoteData('NOTE', null, '<script>alert(1)</script>', null));

    $this->get(brokerUrl('/profiles/'.$profile->code.'/notes'))->assertOk()
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('R-M13-6: a note on — or completing a follow-up of — another bureau’s client is 404', function (): void {
    $login = bureauLogin();
    $foreign = managedProfile(Broker::factory()->create());
    $foreignNote = BrokerClientNote::factory()->create(['profile_id' => $foreign->id, 'broker_id' => $foreign->managed_by_broker_id,
        'follow_up_on' => now()->addDay()->format('Y-m-d')]);

    expect(fn () => app(AddClientNote::class)->handle($login, $foreign->code, new ClientNoteData('NOTE', null, 'Hello there', null)))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => app(CompleteFollowUp::class)->handle($login, $foreignNote->id))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect($foreignNote->refresh()->follow_up_done_at)->toBeNull();
});

it('notes are rate limited per login', function (): void {
    $login = bureauLogin();
    $profile = managedProfile(staffOf($login)->broker);
    Illuminate\Support\Facades\RateLimiter::increment('broker-client-note:'.$login->id, amount: 120);

    expect(fn () => app(AddClientNote::class)->handle($login, $profile->code, new ClientNoteData('NOTE', null, 'Hello there', null)))
        ->toThrow(ValidationException::class);
    expect(BrokerClientNote::query()->count())->toBe(0);
});

// ---- What members see ------------------------------------------------------------------------------

it('B.6: an approved managed profile is a normal profile to members — nothing reveals the bureau or the client mobile', function (): void {
    $this->seed(Database\Seeders\PlansSeeder::class);
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    $profile = managedProfile($broker, ['first_name' => 'Kavya', 'client_phone' => '+919876512399']);

    $this->actingAs(groom(), 'web')->get(memberUrl('/profile/'.$profile->code))
        ->assertOk()->assertSee($profile->code)
        ->assertDontSee($broker->code)->assertDontSee((string) $broker->bureau_name)->assertDontSee('9876512399')
        ->assertDontSee('Added by broker');
});
