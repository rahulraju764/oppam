<?php

declare(strict_types=1);

use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Livewire\Member\Profile\MyProfile;
use App\Livewire\Member\Profile\Show;
use App\Models\ContactDetail;
use App\Models\ContactView;
use App\Models\User;
use App\Support\Navigation\ProfileBrowseList;
use Database\Seeders\PlansSeeder;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.5 — Member\Profile\Show (/profile/{code}) and MyProfile (/me): rendering, the masked name,
| match checklist, contact reveal with confirmation, preview, prev/next, locked props.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
});

/** A complete, live bride (wizard steps 1–6 through the real Actions). */
function liveBride(): User
{
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $contact = ContactDetail::query()->whereKey($user->profile->id)->firstOrFail();
    $contact->forceFill(['contact_person' => 'Gopalan Nair'])->save();

    return $user->refresh();
}

function showAs(User $viewer, User $owner, array $query = []): mixed
{
    test()->actingAs($viewer, 'web');

    return Livewire::withQueryParams($query)->test(Show::class, ['profile' => $owner->profile->code]);
}

it('renders the template profile: masked name, code, tabs, facts and the match checklist', function (): void {
    $bride = liveBride();

    showAs(groom(), $bride)
        ->assertOk()
        ->assertSee('Anjali N.')
        ->assertDontSee('Anjali Nair')
        ->assertSee($bride->profile->code)
        ->assertSeeHtml('class="single-detail"')
        ->assertSee('Personal Information')
        ->assertSee('Partner Preference')
        ->assertSee('Religious Information')
        ->assertSee('Gopalan Nair')               // father (family section), not the contact person
        ->assertSee('You match')
        ->assertSee('Likes, favorites, interests and chat are opening soon.');
});

it('keeps contact details masked; a free member is told to upgrade', function (): void {
    showAs(groom(), liveBride())
        ->assertSee('+91 ••••• •••••')
        ->assertSee('Upgrade your plan to view contact details.')
        ->assertDontSee('family@example.com');
});

it('R-M03-2: a Gold member confirms, sees the details and is charged once', function (): void {
    $bride = liveBride();
    $groom = onPlanFor(groom(), PlanCode::Gold);

    showAs($groom, $bride)
        ->assertSee('View Contact')
        ->call('viewContact')
        ->assertSee($bride->phone)
        ->assertSee('family@example.com')
        ->assertDispatched('close-modal');

    expect(ContactView::query()->count())->toBe(1);
});

it('refuses tampering with the locked profile code and preview flag', function (string $prop, mixed $value): void {
    expect(fn () => showAs(groom(), liveBride())->set($prop, $value))->toThrow(CannotUpdateLockedPropertyException::class);
})->with([['code', 'OPM10001'], ['preview', true]]);

it('404s a blocked pair, a same-gender viewer and a profile that is not live', function (string $case): void {
    $bride = liveBride();
    $viewer = match ($case) {
        'blocked' => tap(groom(), function (User $g) use ($bride): void {
            $block = new App\Models\Block;
            $block->forceFill(['blocker_profile_id' => $g->profile->id, 'blocked_profile_id' => $bride->profile->id])->save();
        }),
        'same gender' => bride('+919800000092'),
        'not live' => tap(groom(), fn () => $bride->profile->forceFill(['status' => ProfileStatus::Hidden])->save()),
    };

    showAs($viewer, $bride)->assertNotFound();
})->with(['blocked', 'same gender', 'not live']);

it('the owner sees their own page and can preview it as others see it (masked name, no edit-only data)', function (): void {
    $bride = liveBride();

    showAs($bride, $bride)->assertSee('Anjali Nair')->assertSee('Preview as others see it');
    showAs($bride, $bride, ['preview' => 1])->assertSee('Anjali N.')->assertSee('This is how other members see your profile.');
});

it('shows Previous / Next from the remembered result list', function (): void {
    $bride = liveBride();
    app(ProfileBrowseList::class)->remember(['OPM90001', $bride->profile->code, 'OPM90003']);

    showAs(groom(), $bride)->assertSee('Previous profile')->assertSee('Next profile')->assertSeeHtml('/profile/OPM90003');
});

it('lists similar profiles (same gender, live, not the viewer)', function (): void {
    $bride = liveBride();
    $other = bride('+919800000091');
    $other->profile->forceFill(['religion_id' => $bride->profile->religion_id, 'dob' => $bride->profile->dob])->save();

    showAs(groom(), $bride)->assertSee('Similar Profiles')->assertSeeHtml('/profile/'.$other->profile->code);
});

// ---- My Profile ------------------------------------------------------------------------------

it('My Profile shows completeness with links to the missing steps, status and weekly views', function (): void {
    $user = memberThroughStep(5);
    $user->profile->forceFill(['status' => ProfileStatus::PendingReview])->save();
    test()->actingAs($user->refresh(), 'web');

    Livewire::test(MyProfile::class)
        ->assertOk()
        ->assertSee('Profile Completeness')
        ->assertSee('80%')
        ->assertSee('Add a photo (+15%)')
        ->assertSee('Your profile is under review.')
        ->assertSee('Profile views');
});

it('My Profile tells a live member which text edits wait for review', function (): void {
    $user = liveBride();
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(), aboutData(['about' => 'Completely rewritten about me that is well over fifty characters long.']));
    test()->actingAs($user->refresh(), 'web');

    Livewire::test(MyProfile::class)
        ->assertSee('Some of your text changes are waiting for a quick review')
        ->assertSee('In review')
        ->assertSee('Edit Profile');
});

it('/me and /profile need an onboarded member', function (): void {
    $draft = memberWithPhone('+919800000090', ProfileStatus::Draft);

    $this->actingAs($draft, 'web')->get(memberUrl('/me'))->assertRedirect(route('member.onboarding', ['step' => 1]));
    auth('web')->logout();
    $this->get(memberUrl('/me'))->assertRedirect(route('login'));
});

function onPlanFor(User $user, PlanCode $plan): User
{
    App\Models\Subscription::factory()->create([
        'profile_id' => $user->profile->id,
        'plan_id' => App\Models\Plan::query()->where('code', $plan->value)->sole()->id,
    ]);
    app(App\Services\Entitlements\EntitlementService::class)->forget($user->profile);

    return $user->refresh();
}

it('a block created after the page opened takes effect on the next action (404, nothing revealed)', function (): void {
    $bride = liveBride();
    $groom = onPlanFor(groom(), PlanCode::Gold);
    $page = showAs($groom, $bride);

    $block = new App\Models\Block;
    $block->forceFill(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id])->save();

    $page->call('viewContact')->assertNotFound();
    expect(ContactView::query()->count())->toBe(0);
});
