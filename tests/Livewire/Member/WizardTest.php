<?php

declare(strict_types=1);

use App\Enums\ProfileStatus;
use App\Livewire\Member\Onboarding\Wizard;
use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\Religion;
use App\Models\Masters\State;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.2 — the wizard component (M02): restores saved data, reactive religion → caste and
| country → state → district, Continue validates + saves + moves on, autosave, step order,
| authorization and locked props.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);   // the member header shows the plan
});

function wizardAs(User $user, int $step = 1): mixed
{
    test()->actingAs($user, 'web');

    return Livewire::test(Wizard::class, ['step' => $step]);
}

it('renders step 1 with the template layout, the step rail and loading state', function (): void {
    wizardAs(draftMember())
        ->assertOk()
        ->assertSee('Profile Creation')
        ->assertSee('Education Details')
        ->assertSeeHtml('class="register-wrapper"')
        ->assertSeeHtml('wire:target="next"')
        ->assertSeeHtml('x-data="wizardAutosave(20000)"');
});

it('acceptance: refreshing mid-wizard restores the saved values', function (): void {
    $user = memberThroughStep(1);

    wizardAs($user, 1)
        ->assertSet('basic.last_name', 'Nair')
        ->assertSet('basic.height_cm', '163')
        ->assertSet('basic.marital_status', 'NEVER_MARRIED');
});

it('acceptance: the caste list follows the religion with no reload, and the old caste is cleared', function (): void {
    $user = draftMember();
    $hindu = masterId(Religion::class, 'HINDU');
    $christian = masterId(Religion::class, 'CHRISTIAN');
    $hinduCaste = Caste::query()->where('religion_id', $hindu)->firstOrFail();
    $christianCaste = Caste::query()->where('religion_id', $christian)->firstOrFail();

    wizardAs($user)
        ->set('basic.religion_id', (string) $hindu)
        ->assertSee($hinduCaste->label)
        ->set('basic.caste_id', (string) $hinduCaste->id)
        ->set('basic.religion_id', (string) $christian)
        ->assertSet('basic.caste_id', '')
        ->assertSee($christianCaste->label)
        ->assertDontSee($hinduCaste->label);
});

it('Continue validates, saves and moves to the next step', function (): void {
    $user = draftMember();
    $data = basicData();

    $component = wizardAs($user);
    foreach ($data->toArray() as $field => $value) {
        $component->set('basic.'.$field, is_bool($value) ? $value : (string) ($value ?? ''));
    }

    $component->call('next')
        ->assertHasNoErrors()
        ->assertRedirect(route('member.onboarding', ['step' => 2]));

    expect($user->profile()->firstOrFail()->last_name)->toBe('Nair');
});

it('shows errors under the fields and stays on the step when something is missing', function (): void {
    wizardAs(draftMember())
        ->set('basic.last_name', '')
        ->call('next')
        ->assertHasErrors(['basic.last_name', 'basic.dob', 'basic.height_cm', 'basic.marital_status', 'basic.religion_id', 'basic.mother_tongue_id'])
        ->assertNoRedirect();
});

it('autosave keeps valid partial input and says when it saved', function (): void {
    $user = draftMember();

    wizardAs($user)
        ->set('basic.height_cm', '170')
        ->call('autosave')
        ->assertNotSet('savedAt', null);

    expect($user->profile()->firstOrFail()->height_cm)->toBe(170);
});

it('autosave stays silent and saves nothing while a value is invalid', function (): void {
    $user = draftMember();

    wizardAs($user)
        ->set('basic.height_cm', '999')
        ->call('autosave')
        ->assertHasNoErrors()
        ->assertSet('savedAt', null);

    expect($user->profile()->firstOrFail()->height_cm)->toBeNull();
});

it('Back keeps what was typed on the step before leaving it', function (): void {
    $user = memberThroughStep(1);

    wizardAs($user, 2)
        ->set('career.employer_name', 'Infopark')
        ->call('previous')
        ->assertRedirect(route('member.onboarding', ['step' => 1]));

    expect($user->profile()->firstOrFail()->educationCareer?->employer_name)->toBe('Infopark');
});

it('caps autosave per member so a scripted loop cannot flood writes', function (): void {
    $user = draftMember();
    RateLimiter::increment('wizard-autosave:'.$user->id, amount: 30);

    wizardAs($user)->set('basic.height_cm', '170')->call('autosave')->assertSet('savedAt', null);

    expect($user->profile()->firstOrFail()->height_cm)->toBeNull();
});

it('state and district follow the country, and change of country clears them', function (): void {
    $user = memberThroughStep(1);
    $india = masterId(Country::class, 'IN');
    $kerala = masterId(State::class, 'KL');

    wizardAs($user, 2)
        ->set('career.current_country_id', (string) $india)
        ->assertSee('Kerala')
        ->set('career.current_state_id', (string) $kerala)
        ->assertSee('Ernakulam')
        ->set('career.current_district_id', (string) keralaDistrictId())
        ->set('career.current_country_id', (string) masterId(Country::class, 'AE'))
        ->assertSet('career.current_state_id', '')
        ->assertSet('career.current_district_id', '')
        ->assertSee('Citizenship');   // NRI fields for a country without states
});

it('opens steps only in order: a deep link to a later step goes back to the first unfinished one', function (): void {
    wizardAs(draftMember(), 3)->assertRedirect(route('member.onboarding', ['step' => 1]));
    wizardAs(memberThroughStep(2), 3)->assertOk()->assertSee('Father');
});

it('sends members with a submitted or live profile away from the wizard', function (ProfileStatus $status, string $route): void {
    $user = memberThroughStep(3);
    $user->profile->forceFill(['status' => $status, 'published_at' => now()])->save();

    wizardAs($user->refresh())->assertRedirect(route($route));
})->with([
    'pending review → under-review page' => [ProfileStatus::PendingReview, 'member.onboarding.submitted'],
    'live → home (dashboard from P2.3)' => [ProfileStatus::Active, 'home'],
]);

it('lets a REJECTED profile back in to edit and resubmit (R-M02-5)', function (): void {
    $user = memberThroughStep(3);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected])->save();

    wizardAs($user->refresh(), 1)->assertOk()->assertSee('needs changes');
});

it('404s broker logins and refuses a tampered step', function (): void {
    test()->actingAs(User::factory()->broker()->create(), 'web');
    Livewire::test(Wizard::class, ['step' => 1])->assertNotFound();

    expect(fn () => wizardAs(memberThroughStep(1), 1)->set('step', 3))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('404s a member whose profile is missing or soft-deleted instead of looping back to the landing', function (): void {
    $user = memberThroughStep(1);
    $user->profile()->firstOrFail()->delete();

    wizardAs($user->refresh())->assertNotFound();
});

it('never lets the browser pick the profile: saving always writes the signed-in member\'s own', function (): void {
    $victim = memberThroughStep(1);
    $attacker = draftMember();

    wizardAs($attacker)->set('basic.first_name', 'Hacked')->set('basic.height_cm', '170')->call('autosave');

    expect($victim->profile()->firstOrFail()->first_name)->not->toBe('Hacked')
        ->and($attacker->profile()->firstOrFail()->height_cm)->toBe(170);
});

it('words errors with friendly field names, not column names', function (): void {
    wizardAs(draftMember())
        ->call('next')
        ->assertHasErrors(['basic.dob'])
        ->assertSee('The date of birth field is required.')
        ->assertDontSee('The dob field');
});

// ---- P1.3: steps 4–6, submit -----------------------------------------------------------------

it('step 4 suggests an age range around the member and their own religion on first visit', function (): void {
    $user = memberThroughStep(3);

    wizardAs($user, 4)
        ->assertOk()
        ->assertSee('Partner Preference')
        ->assertSet('preference.religion_ids', [(string) $user->profile->religion_id])
        ->assertSet('preference.age_min', (string) $user->profile->age());
});

it('step 4 option buttons toggle a list, "Any" clears it, and unknown lists are ignored', function (): void {
    $wizard = wizardAs(memberThroughStep(3), 4)
        ->call('toggleChoice', 'marital_statuses', 'DIVORCED')
        ->assertSet('preference.marital_statuses', ['DIVORCED'])
        ->call('toggleChoice', 'marital_statuses', 'DIVORCED')
        ->assertSet('preference.marital_statuses', [])
        ->call('toggleChoice', 'marital_statuses', 'WIDOWED')
        ->call('clearChoice', 'marital_statuses')
        ->assertSet('preference.marital_statuses', []);

    // An attacker naming another property gets nothing.
    $wizard->call('toggleChoice', 'savedAt', 'x')->assertSet('savedAt', null);
});

it('step 4: deselecting a religion drops its castes from the partner caste list', function (): void {
    $user = memberThroughStep(3);
    $hindu = (string) masterId(Religion::class, 'HINDU');
    $caste = (string) Caste::query()->where('religion_id', (int) $hindu)->value('id');

    wizardAs($user, 4)
        ->set('preference.caste_ids', [$caste])
        ->call('toggleChoice', 'religion_ids', $hindu)
        ->assertSet('preference.religion_ids', [])
        ->assertSet('preference.caste_ids', []);
});

it('step 4 Continue saves preferences and opens step 5', function (): void {
    $user = memberThroughStep(3);

    wizardAs($user, 4)
        ->set('preference.age_max', '30')
        ->call('next')
        ->assertHasNoErrors()
        ->assertRedirect(route('member.onboarding', ['step' => 5]));

    expect($user->profile()->firstOrFail()->partnerPreference?->age_max)->toBe(30);
});

it('step 5 shows the verified mobile read-only and starts from the account email and native address', function (): void {
    $user = memberThroughStep(4);
    $user->forceFill(['email' => 'me@example.com'])->save();

    wizardAs($user->refresh(), 5)
        ->assertSeeHtml('id="contact-mobile"')
        ->assertSeeHtml('readonly')
        ->assertSet('contact.contact_email', 'me@example.com')
        ->assertSet('contact.district_id', (string) keralaDistrictId());
});

it('step 5: changing the country clears state and district', function (): void {
    wizardAs(memberThroughStep(4), 5)
        ->set('contact.country_id', (string) Country::query()->where('code', '!=', 'IN')->value('id'))
        ->assertSet('contact.state_id', '')
        ->assertSet('contact.district_id', '');
});

it('step 6 submits for review and lands on the "under review" page (R-M02-2)', function (): void {
    $user = memberThroughStep(5);

    wizardAs($user, 6)
        ->assertSee('Submit for review')
        ->set('about.about', 'I am a software engineer in Kochi who loves music, travel and time with family.')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('member.onboarding.submitted'));

    expect(statusOf($user))->toBe(ProfileStatus::PendingReview);
});

it('step 6 shows the error under About Me and does not submit when it is too short', function (): void {
    $user = memberThroughStep(5);

    wizardAs($user, 6)
        ->set('about.about', 'Too short')
        ->call('submit')
        ->assertHasErrors(['about.about']);

    expect(statusOf($user))->toBe(ProfileStatus::Draft);
});

it('R-M02-5: a rejected profile shows the reviewer\'s note in the wizard', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected])->save();
    App\Models\ModerationItem::factory()->rejected('Please add a clearer about-me.')->create(['profile_id' => $user->profile->id]);

    wizardAs($user->refresh(), 1)->assertSee('Please add a clearer about-me.');
});

it('R-M02-1: after first publish the locked fields render disabled with an explanation', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Rejected, 'published_at' => now()->subMonth()])->save();

    wizardAs($user->refresh(), 1)
        ->assertSeeHtml('id="basic-locked-hint"')
        ->assertSeeHtml('id="basic-dob"');
});

it('the "under review" page shows only for a pending profile and sends others on', function (): void {
    $user = memberThroughStep(6);
    test()->actingAs($user, 'web');
    Livewire::test(App\Livewire\Member\Onboarding\Submitted::class)->assertRedirect(route('member.onboarding', ['step' => 1]));

    app(App\Actions\Profile\SubmitProfile::class)->handle($user, $user->profile()->firstOrFail());
    test()->actingAs($user->refresh(), 'web');   // a new request loads the profile afresh

    Livewire::test(App\Livewire\Member\Onboarding\Submitted::class)
        ->assertOk()
        ->assertSee($user->profile->code)
        ->assertSee('under review');
});

it('signing in lands a pending member on "under review" and a rejected member back in the wizard', function (): void {
    $landing = app(App\Support\Navigation\MemberLanding::class);
    $user = memberThroughStep(6);

    $user->profile->forceFill(['status' => ProfileStatus::PendingReview])->save();
    expect($landing->url($user->refresh()))->toBe(route('member.onboarding.submitted'));

    $user->profile->forceFill(['status' => ProfileStatus::Rejected])->save();
    expect($landing->url($user->refresh()))->toBe(route('member.onboarding', ['step' => 1]));
});

it('step 6 submits with hobbies typed as comma-separated text (review Major)', function (): void {
    $user = memberThroughStep(5);

    wizardAs($user, 6)
        ->set('about.about', 'I am a software engineer in Kochi who loves music, travel and time with family.')
        ->set('about.hobbies', 'Music, Travel, Cooking')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('member.onboarding.submitted'));

    expect($user->profile()->firstOrFail()->lifestyleDetail->hobbies)->toBe(['Music', 'Travel', 'Cooking']);
});

it('step 6 explains too many or too long hobbies under the field', function (string $hobbies): void {
    wizardAs(memberThroughStep(5), 6)
        ->set('about.about', 'I am a software engineer in Kochi who loves music, travel and time with family.')
        ->set('about.hobbies', $hobbies)
        ->call('submit')
        ->assertHasErrors(['about.hobbies']);
})->with([
    'eleven hobbies' => [implode(', ', array_map(fn (int $i): string => 'Hobby '.$i, range(1, 11)))],
    'one too long' => [str_repeat('a', 41)],
]);

it('step 4 never suggests or offers a partner age below the legal minimum (bride aged 19 → grooms from 21)', function (): void {
    $user = memberThroughStep(3);
    $user->profile->forceFill(['dob' => now(config('oppam.display_timezone'))->subYears(19)->format('Y-m-d')])->save();

    wizardAs($user->refresh(), 4)
        ->assertSet('preference.age_min', '21')
        ->assertDontSeeHtml('<option value="20"')
        ->call('next')
        ->assertHasNoErrors(['preference.age_min']);
});
