<?php

declare(strict_types=1);

use App\Actions\Profile\SaveBasicDetails;
use App\Actions\Profile\SubmitProfile;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Enums\WizardStep;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Profile\ProfileSubmitted;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\Masters\Religion;
use App\Models\ModerationItem;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| P1.3 — submit for review (R-M02-2), rejected profiles (R-M02-5) and identity-field locks after
| first publish (R-M02-1).
*/

beforeEach(function (): void {
    seedMasters();
});

function submitProfile(User $user): ModerationItem
{
    return app(SubmitProfile::class)->handle($user, $user->profile()->firstOrFail());
}

// ---- R-M02-2: submit ------------------------------------------------------------------------------

it('R-M02-2: submitting a finished wizard moves DRAFT → PENDING_REVIEW and opens one A04 queue item', function (): void {
    $user = memberThroughStep(6);

    $item = submitProfile($user);

    $profile = $user->profile()->firstOrFail();

    expect($profile->status)->toBe(ProfileStatus::PendingReview)
        ->and($profile->status->isSearchable())->toBeFalse()
        ->and($profile->completeness)->toBe(100)
        ->and($item->type)->toBe(ModerationItemType::ProfileNew)
        ->and($item->status)->toBe(ModerationStatus::Open)
        ->and($item->profile_id)->toBe($profile->id)
        ->and($item->is_priority)->toBeFalse()
        ->and(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->count())->toBe(1);
});

it('R-M02-2 acceptance: submitting fires ProfileSubmitted and a live count on admin.queues', function (): void {
    Event::fake([AdminQueueCountChanged::class]);
    ModerationItem::factory()->count(2)->create();     // two profiles already waiting
    $user = memberThroughStep(6);

    submitProfile($user);

    Event::assertDispatched(AdminQueueCountChanged::class, function (AdminQueueCountChanged $event): bool {
        return $event->broadcastOn()[0]->name === 'private-admin.queues'
            && $event->broadcastAs() === 'queue.count'
            && $event->broadcastWith() === ['queue' => 'moderation.profiles', 'count' => 3];
    });
});

it('broadcasts only codes and counts: the queue event carries no ULIDs or member data', function (): void {
    $event = new AdminQueueCountChanged('moderation.profiles', 4);

    expect(array_keys($event->broadcastWith()))->toBe(['queue', 'count'])
        ->and($event->queue)->toBe('broadcasts');
});

it('fires ProfileSubmitted only after the transaction commits', function (): void {
    Event::fake([ProfileSubmitted::class]);
    $user = memberThroughStep(6);

    submitProfile($user);

    Event::assertDispatched(ProfileSubmitted::class, fn (ProfileSubmitted $e): bool => $e->profileCode === $user->profile->code);
    expect(new ProfileSubmitted('OPM1', 'x'))->toBeInstanceOf(Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class);
});

it('puts paid members in the priority lane', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['is_premium' => true])->save();

    expect(submitProfile($user->refresh())->is_priority)->toBeTrue();
});

it('refuses to submit an incomplete profile and names the first unfinished step', function (): void {
    $user = memberThroughStep(4);

    try {
        submitProfile($user);
        $this->fail('Expected ProfileNotSubmittable');
    } catch (ProfileNotSubmittable $e) {
        expect($e->step)->toBe(WizardStep::Contact);
    }

    expect(statusOf($user))->toBe(ProfileStatus::Draft)
        ->and(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->count())->toBe(0);
});

it('can\'t be submitted twice: the second attempt is refused and no second item is created', function (): void {
    $user = memberThroughStep(6);
    submitProfile($user);

    expect(fn () => submitProfile($user->refresh()))->toThrow(AuthorizationException::class)
        ->and(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->count())->toBe(1);
});

it('R-M02-5: a REJECTED profile can be edited and resubmitted, creating a fresh queue item', function (): void {
    $user = memberThroughStep(6);
    $first = submitProfile($user);
    $first->forceFill(['status' => ModerationStatus::Rejected, 'reason_note' => 'Add your full name.', 'decided_at' => now()])->save();
    $user->profile->forceFill(['status' => ProfileStatus::Rejected])->save();

    $second = submitProfile($user->refresh());

    expect(statusOf($user))->toBe(ProfileStatus::PendingReview)
        ->and($second->id)->not->toBe($first->id)
        ->and(ModerationItem::latestDecisionNote($user->profile))->toBe('Add your full name.');
});

it('security matrix: refuses submit by another member, a suspended member, or for a live profile', function (Closure $arrange): void {
    $user = memberThroughStep(6);
    $actor = $arrange($user) ?? $user;

    expect(fn () => app(SubmitProfile::class)->handle($actor->refresh(), $user->profile()->firstOrFail()))
        ->toThrow(AuthorizationException::class)
        ->and(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->count())->toBe(0);
})->with([
    'another member' => fn (User $u) => memberThroughStep(6),
    'suspended member' => fn (User $u) => tap($u)->forceFill(['status' => UserStatus::Suspended])->save() ? null : null,
]);

it('a live profile has nothing to submit: the attempt is refused and no item is created', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();

    expect(fn () => submitProfile($user->refresh()))->toThrow(ProfileNotSubmittable::class, 'already been submitted')
        ->and(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->count())->toBe(0);
});

// ---- R-M02-1: identity fields locked after first publish ---------------------------------------

it('R-M02-1: after first publish, gender, DOB, religion and marital status can no longer be changed', function (string $field, Closure $value): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected, 'published_at' => now()->subMonth()])->save();

    $attempt = fn () => app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData([$field => $value(), ...($field === 'religion_id' ? ['caste_id' => null] : [])]));

    try {
        $attempt();
        $this->fail('Expected a ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'gender' => ['gender', fn () => Gender::Male->value],
    'dob' => ['dob', fn () => now()->subYears(30)->format('Y-m-d')],
    'religion' => ['religion_id', fn () => masterId(Religion::class, 'CHRISTIAN')],
    'marital status' => ['marital_status', fn () => MaritalStatus::Divorced->value],
]);

it('R-M02-1: other step-1 fields stay editable after publish, and unchanged locked fields pass', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected, 'published_at' => now()->subMonth()])->save();

    app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData(['height_cm' => 170]));

    expect($user->profile()->firstOrFail()->height_cm)->toBe(170);
});

it('R-M02-1: before first publish everything is still editable', function (): void {
    $user = memberThroughStep(1);

    app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData(['marital_status' => MaritalStatus::Divorced->value]));

    expect($user->profile()->firstOrFail()->marital_status)->toBe(MaritalStatus::Divorced);
});

it('keeps gender as "profile for" implies it (registered for a daughter → female)', function (): void {
    $user = memberThroughStep(1);
    $user->forceFill(['created_for' => App\Enums\CreatedFor::Daughter])->save();

    expect(fn () => app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData(['gender' => Gender::Male->value])))
        ->toThrow(ValidationException::class);
});
