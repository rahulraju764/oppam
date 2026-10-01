<?php

declare(strict_types=1);

use App\Actions\Profile\SaveAboutDetails;
use App\Actions\Profile\SaveContactDetails;
use App\Actions\Profile\SavePartnerPreferences;
use App\Enums\Gender;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Religion;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/*
| P1.3 — wizard steps 4–6 (M02): SavePartnerPreferences / SaveContactDetails / SaveAboutDetails.
| Rules from ProfileRules, authorization via ProfilePolicy::editWizard, partial autosave,
| completeness kept current on every save (R-M02-3).
*/

beforeEach(function (): void {
    seedMasters();
});

function stepErrors(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

function savePreferences(User $user, array $overrides = [], bool $partial = false): void
{
    app(SavePartnerPreferences::class)->handle($user, $user->profile()->firstOrFail(), preferenceData($overrides), $partial);
}

function saveContact(User $user, array $overrides = [], bool $partial = false): void
{
    app(SaveContactDetails::class)->handle($user, $user->profile()->firstOrFail(), contactData($overrides), $partial);
}

function saveAbout(User $user, array $overrides = [], bool $partial = false): void
{
    app(SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData($overrides), $partial);
}

// ---- Step 4: partner preferences --------------------------------------------------------------

it('saves step 4 with id lists stored as integers and an empty list meaning "any"', function (): void {
    $user = memberThroughStep(3);

    savePreferences($user, ['star_ids' => []]);

    $preference = $user->profile()->firstOrFail()->partnerPreference;

    expect($preference->age_min)->toBe(25)
        ->and($preference->age_max)->toBe(32)
        ->and($preference->religion_ids)->toBe([masterId(Religion::class, 'HINDU')])
        ->and($preference->star_ids)->toBe([])
        ->and($preference->marital_statuses)->toBe(['NEVER_MARRIED']);
});

it('M02 step 4: the partner age starts at the legal minimum for the partner\'s gender', function (Gender $own, int $ageMin, bool $ok): void {
    $user = memberThroughStep(3, $own);

    $errors = stepErrors(fn () => savePreferences($user, ['age_min' => $ageMin, 'age_max' => 35]));

    expect(in_array('age_min', $errors, true))->toBe(! $ok);
})->with([
    'bride looking for a 20-year-old groom' => [Gender::Female, 20, false],
    'bride looking for a 21-year-old groom' => [Gender::Female, 21, true],
    'groom looking for a 17-year-old bride' => [Gender::Male, 17, false],
    'groom looking for an 18-year-old bride' => [Gender::Male, 18, true],
]);

it('refuses an age or height range that runs backwards', function (array $overrides, string $field): void {
    $user = memberThroughStep(3);

    expect(stepErrors(fn () => savePreferences($user, $overrides)))->toContain($field);
})->with([
    'age' => [['age_min' => 30, 'age_max' => 26], 'age_max'],
    'height' => [['height_min_cm' => 180, 'height_max_cm' => 160], 'height_max_cm'],
]);

it('requires at least one religion on Continue but not on autosave', function (): void {
    $user = memberThroughStep(3);

    expect(stepErrors(fn () => savePreferences($user, ['religion_ids' => []])))->toContain('religion_ids');

    savePreferences($user, ['religion_ids' => [], 'age_max' => null], partial: true);
    expect($user->profile()->firstOrFail()->partnerPreference?->age_max)->toBeNull();
});

it('refuses partner castes that do not belong to the chosen religions (tampered request)', function (): void {
    $user = memberThroughStep(3);
    $christianCaste = (int) Caste::query()->where('religion_id', masterId(Religion::class, 'CHRISTIAN'))->value('id');
    $hinduCaste = (int) Caste::query()->where('religion_id', masterId(Religion::class, 'HINDU'))->value('id');

    expect(stepErrors(fn () => savePreferences($user, ['caste_ids' => [$christianCaste]])))->toContain('caste_ids');

    savePreferences($user, ['caste_ids' => [$hinduCaste]]);
    expect($user->profile()->firstOrFail()->partnerPreference->caste_ids)->toBe([$hinduCaste]);
});

it('refuses unknown or inactive ids inside the lists', function (string $list): void {
    $user = memberThroughStep(3);
    $inactive = District::query()->where('id', keralaDistrictId('THRISSUR'))->firstOrFail();
    $inactive->forceFill(['is_active' => false])->save();

    $value = $list === 'district_ids' ? $inactive->id : 999999;

    expect(stepErrors(fn () => savePreferences($user, [$list => [$value]])))->toContain($list.'.0');
})->with(['religion_ids', 'mother_tongue_ids', 'education_ids', 'country_ids', 'district_ids']);

it('refuses a diet option from another master group', function (): void {
    $user = memberThroughStep(3);

    expect(stepErrors(fn () => savePreferences($user, ['diet_option_ids' => [optionId('smoking', 'NO')]])))
        ->toContain('diet_option_ids.0');
});

// ---- Step 5: contact ----------------------------------------------------------------------------

it('saves step 5 with a lower-cased email and the alternate mobile in E.164', function (): void {
    $user = memberThroughStep(4);

    saveContact($user, ['contact_email' => 'Family@Example.COM', 'alternate_phone' => '98470 12345']);

    $contact = $user->profile()->firstOrFail()->contactDetail;

    expect($contact->contact_email)->toBe('family@example.com')
        ->and($contact->alternate_phone)->toBe('+919847012345')
        ->and($contact->city)->toBe('Kochi');
});

it('accepts an international alternate mobile and refuses a bad one', function (): void {
    $user = memberThroughStep(4);

    saveContact($user, ['alternate_phone' => '+971 50 123 4567']);
    expect($user->profile()->firstOrFail()->contactDetail->alternate_phone)->toBe('+971501234567');

    expect(stepErrors(fn () => saveContact($user, ['alternate_phone' => '12345'])))->toContain('alternate_phone');
});

it('requires email, country and city, and state + district for India', function (array $overrides, string $field): void {
    $user = memberThroughStep(4);

    expect(stepErrors(fn () => saveContact($user, $overrides)))->toContain($field);
})->with([
    'email' => [['contact_email' => null], 'contact_email'],
    'bad email' => [['contact_email' => 'not-an-email'], 'contact_email'],
    'country' => [['country_id' => null], 'country_id'],
    'city' => [['city' => null], 'city'],
    'state for India' => [['state_id' => null, 'district_id' => null], 'state_id'],
    'district for India' => [['district_id' => null], 'district_id'],
]);

it('lets a family abroad leave state and district empty', function (): void {
    $user = memberThroughStep(4);
    $uae = (int) Country::query()->where('code', '!=', 'IN')->value('id');

    saveContact($user, ['country_id' => $uae, 'state_id' => null, 'district_id' => null, 'city' => 'Dubai']);

    expect($user->profile()->firstOrFail()->contactDetail->country_id)->toBe($uae);
});

it('never changes the verified primary mobile', function (): void {
    $user = memberThroughStep(4);
    $phone = $user->phone;

    saveContact($user, ['alternate_phone' => '9847012345']);

    expect($user->refresh()->phone)->toBe($phone);
});

// ---- Step 6: about, lifestyle, photo visibility ------------------------------------------------

it('saves step 6: about me, lifestyle, hobbies and photo visibility', function (): void {
    $user = memberThroughStep(5);

    saveAbout($user, ['hobbies' => ['Music', ' Music ', 'Travel', ''], 'photo_visibility' => PhotoVisibility::PremiumOnly->value]);

    $profile = $user->profile()->firstOrFail();

    expect($profile->about)->toStartWith('I am a software engineer')
        ->and($profile->lifestyleDetail->hobbies)->toBe(['Music', 'Travel'])
        ->and($profile->lifestyleDetail->diet_option_id)->toBe(optionId('diet', 'VEG'))
        ->and($profile->privacySetting->photo_visibility)->toBe(PhotoVisibility::PremiumOnly);
});

it('M02 step 6: about me needs at least 50 characters', function (): void {
    $user = memberThroughStep(5);

    expect(stepErrors(fn () => saveAbout($user, ['about' => 'Too short.'])))->toContain('about')
        ->and(stepErrors(fn () => saveAbout($user, ['about' => str_repeat('a', 1001)])))->toContain('about');
});

it('refuses an unknown photo visibility and a lifestyle option from the wrong group', function (): void {
    $user = memberThroughStep(5);

    expect(stepErrors(fn () => saveAbout($user, ['photo_visibility' => 'EVERYONE_ON_EARTH'])))->toContain('photo_visibility')
        ->and(stepErrors(fn () => saveAbout($user, ['smoking_option_id' => optionId('diet', 'VEG')])))->toContain('smoking_option_id');
});

it('stores about-me text as typed, escaped only on output (no HTML is executed)', function (): void {
    $user = memberThroughStep(5);
    $about = '<script>alert(1)</script> I am a teacher from Kottayam who loves books and music.';

    saveAbout($user, ['about' => $about]);

    expect($user->profile()->firstOrFail()->about)->toBe($about);
});

// ---- Completeness (R-M02-3) ---------------------------------------------------------------------

it('R-M02-3: completeness follows the weights as each step is saved', function (int $steps, int $percent): void {
    $user = memberThroughStep($steps);

    expect($user->profile()->firstOrFail()->completeness)->toBe($percent);
})->with([
    'step 1 (basic 25)' => [1, 25],
    'steps 1–2 (+ career 15)' => [2, 40],
    'steps 1–3 (+ family 15)' => [3, 55],
    'steps 1–4 (+ preferences 15)' => [4, 70],
    'steps 1–5 (+ contact 10)' => [5, 80],
    'steps 1–6 (+ about 5 + photo 15)' => [6, 100],
]);

it('R-M02-3: a half-saved step adds nothing', function (): void {
    $user = memberThroughStep(3);

    savePreferences($user, ['religion_ids' => []], partial: true);

    expect($user->profile()->firstOrFail()->completeness)->toBe(55);
});

// ---- Authorization (security matrix) -------------------------------------------------------------

it('refuses to save steps 4–6 of another member\'s profile', function (string $step): void {
    $owner = memberThroughStep(5);
    $other = memberThroughStep(5);

    $attempt = match ($step) {
        'preferences' => fn () => app(SavePartnerPreferences::class)->handle($other, $owner->profile, preferenceData()),
        'contact' => fn () => app(SaveContactDetails::class)->handle($other, $owner->profile, contactData()),
        'about' => fn () => app(SaveAboutDetails::class)->handle($other, $owner->profile, aboutData()),
    };

    expect($attempt)->toThrow(AuthorizationException::class);
})->with(['preferences', 'contact', 'about']);

it('refuses wizard saves on a pending or suspended profile, and by a suspended member', function (Closure $arrange): void {
    $user = memberThroughStep(5);
    $arrange($user);

    expect(fn () => saveAbout($user->refresh()))->toThrow(AuthorizationException::class);
})->with([
    'PENDING_REVIEW profile' => fn (User $u) => $u->profile->forceFill(['status' => ProfileStatus::PendingReview])->save(),
    'SUSPENDED profile' => fn (User $u) => $u->profile->forceFill(['status' => ProfileStatus::Suspended])->save(),
    'suspended member' => fn (User $u) => $u->forceFill(['status' => UserStatus::Suspended])->save(),
]);
