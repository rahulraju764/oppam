<?php

declare(strict_types=1);

use App\Actions\Profile\SaveAboutDetails;
use App\Actions\Profile\SaveBasicDetails;
use App\Actions\Profile\SaveFamilyDetails;
use App\Domain\Profile\PendingTextEdits;
use App\Enums\ModerationItemType;
use App\Enums\ProfileStatus;
use App\Models\ModerationItem;
use App\Models\User;

/*
| P1.5 — R-M02-4: on a LIVE profile, edited names / about texts wait in one PROFILE_EDIT item
| while others keep seeing the approved text; other fields save at once; R-M02-1 locks hold.
*/

beforeEach(fn () => seedMasters());

function liveMember(): User
{
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()->subWeek()])->save();

    return $user->refresh();
}

function editItems(): int
{
    return ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->count();
}

it('R-M02-4: a changed about-me waits for review; others keep seeing the approved text', function (): void {
    $user = liveMember();
    $approved = $user->profile->about;

    app(SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData(['about' => 'A brand new about me text that is certainly longer than fifty characters.']));

    $profile = $user->profile()->firstOrFail();
    expect($profile->about)->toBe($approved)
        ->and(app(PendingTextEdits::class)->pending($profile))->toBe(['profiles.about' => 'A brand new about me text that is certainly longer than fifty characters.'])
        ->and(editItems())->toBe(1);
});

it('R-M02-4: other fields save straight away; several text edits share ONE queue item', function (): void {
    $user = liveMember();
    $profile = $user->profile()->firstOrFail();

    app(SaveBasicDetails::class)->handle($user, $profile, basicData(['first_name' => 'Anjana', 'height_cm' => 170]));
    app(SaveFamilyDetails::class)->handle($user, $profile->refresh(), familyData(['about_family' => 'A close-knit family of teachers.']));

    $profile->refresh();
    expect($profile->first_name)->toBe('Anjali')           // name held
        ->and($profile->height_cm)->toBe(170)              // saved
        ->and(editItems())->toBe(1)
        ->and(app(PendingTextEdits::class)->pending($profile))->toBe([
            'profiles.first_name' => 'Anjana',
            'family_details.about_family' => 'A close-knit family of teachers.',
        ]);
});

it('typing the approved text back withdraws the edit (and the item when nothing is left)', function (): void {
    $user = liveMember();
    $profile = $user->profile()->firstOrFail();

    app(SaveBasicDetails::class)->handle($user, $profile, basicData(['first_name' => 'Anjana']));
    expect(editItems())->toBe(1);

    app(SaveBasicDetails::class)->handle($user, $profile->refresh(), basicData(['first_name' => 'Anjali']));
    expect(editItems())->toBe(0);
});

it('clearing an optional text applies at once (removing text needs no review)', function (): void {
    $user = liveMember();
    $profile = $user->profile()->firstOrFail();
    app(SaveFamilyDetails::class)->handle($user, $profile, familyData(['about_family' => 'Old text']));
    // On a live profile that was held; approve it by hand to set up the scenario.
    $profile->familyDetail->forceFill(['about_family' => 'Old text'])->save();
    ModerationItem::query()->delete();

    app(SaveFamilyDetails::class)->handle($user, $profile->refresh(), familyData(['about_family' => null]));

    expect($profile->refresh()->familyDetail->about_family)->toBeNull()
        ->and(editItems())->toBe(0);
});

it('drafts and rejected profiles write text directly (nothing to review yet)', function (): void {
    $user = memberThroughStep(1);

    app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData(['first_name' => 'Anjana']));

    expect($user->profile()->firstOrFail()->first_name)->toBe('Anjana')->and(editItems())->toBe(0);
});

it('R-M02-1 still holds while editing a live profile', function (): void {
    $user = liveMember();

    expect(fn () => app(SaveBasicDetails::class)->handle($user, $user->profile()->firstOrFail(), basicData(['marital_status' => 'DIVORCED'])))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});

it('a profile under review can\'t be edited (nothing moves while a moderator looks at it)', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::PendingReview])->save();

    expect(fn () => app(SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(), aboutData()))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);
});
