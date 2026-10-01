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

it('sends members with a submitted or live profile away from the wizard', function (ProfileStatus $status): void {
    $user = memberThroughStep(3);
    $user->profile->forceFill(['status' => $status, 'published_at' => now()])->save();

    wizardAs($user->refresh())->assertRedirect(route('home'));
})->with([ProfileStatus::PendingReview, ProfileStatus::Active]);

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
