<?php

declare(strict_types=1);

use App\Enums\ClosureReason;
use App\Enums\ProfileStatus;
use App\Jobs\Profile\GenerateDataExportJob;
use App\Livewire\Member\Settings\ManageProfileSettings;
use App\Livewire\Member\Settings\Settings;
use App\Models\Interest;
use App\Models\SuccessStory;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P8.2c — /settings → Hide / delete tab (M14, M16): renders each card with its states, calls the
| Actions, shows refusals as toasts or field errors, and never trusts a story code that isn't the
| member's own. The rules themselves are proved in the Feature tests.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    fakeMediaDisks();
    Storage::fake(GenerateDataExportJob::disk());
    Cache::flush();
    Notification::fake();
});

function mpTab(): mixed
{
    return Livewire::withoutLazyLoading()->test(ManageProfileSettings::class);
}

it('is a tab of /settings, lazy with a skeleton, and renders its four cards with loading markup', function (): void {
    $this->actingAs(bride(), 'web');

    Livewire::withQueryParams(['tab' => 'manage'])->test(Settings::class)->assertSet('tab', 'manage');
    Livewire::test(ManageProfileSettings::class)->assertSeeHtml('ui-skeleton');

    mpTab()->assertOk()
        ->assertSee('Hide my profile')->assertSee('Download my data')->assertSee('Found your match on Oppam?')->assertSee('Close my account')
        ->assertSeeHtml('wire:target="hide"')->assertSeeHtml('wire:target="requestExport"');
});

it('hide → toast + leaves the online roster; the card then offers "show again"; a pending profile can\'t hide', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    mpTab()->call('hide')->assertDispatched('presence-hidden')->assertDispatched('toast', type: 'success')
        ->assertSee('Show my profile again');
    expect(statusOf($bride))->toBe(ProfileStatus::Hidden);

    mpTab()->call('unhide')->assertDispatched('toast', type: 'success');
    expect(statusOf($bride))->toBe(ProfileStatus::Active);

    $this->actingAs(memberWithPhone('+919800000011', ProfileStatus::PendingReview), 'web');
    mpTab()->assertSee('once it is live')->call('hide')->assertDispatched('toast', type: 'danger');
});

it('export: preparing → ready with a download link (by code); a second ask the same day is a toast', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    mpTab()->call('requestExport')->assertDispatched('toast', type: 'success')
        ->assertSee('Download')->assertSee('/settings/data-export/DEX', false);

    mpTab()->call('requestExport')->assertDispatched('toast', type: 'danger');
});

it('story: "Found my match on Oppam" in the close form opens the story form instead of closing', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    mpTab()->set('closeReason', ClosureReason::MarriedViaOppam->value)
        ->assertSee('You don’t need to close your account')
        ->set('closePassword', TEST_MEMBER_PASSWORD)->call('closeAccount')
        ->assertSet('showStoryForm', true)->assertDispatched('close-modal', name: 'close-account');

    expect($bride->refresh()->trashed())->toBeFalse();
});

it('story: validation on the form; a valid one is sent, the profile hidden, the partner can confirm from their tab', function (): void {
    $bride = bride();
    $groom = groom();
    Interest::factory()->accepted()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);
    $this->actingAs($bride, 'web');

    mpTab()->set('showStoryForm', true)->set('partnerCode', 'nope')->set('storyBody', 'short')->call('submitStory')
        ->assertHasErrors(['partnerCode', 'weddingDate', 'quote', 'storyBody']);

    mpTab()->set('showStoryForm', true)
        ->set('partnerCode', $groom->profile->code)->set('weddingDate', '2026-10-12')
        ->set('quote', 'Two families, one Oppam interest.')->set('storyBody', str_repeat('A long and happy story. ', 4))
        ->set('storyPhoto', UploadedFile::fake()->image('us.jpg', 800, 600))
        ->call('submitStory')->assertHasNoErrors()->assertDispatched('toast', type: 'success')->assertSee('Waiting for your partner');
    expect(statusOf($bride))->toBe(ProfileStatus::Hidden);

    $code = SuccessStory::query()->sole()->code;
    $this->actingAs($groom, 'web');
    mpTab()->assertSee('Yes, share our story')->call('answerStory', $code, true)->assertDispatched('toast', type: 'success');
    expect(statusOf($groom))->toBe(ProfileStatus::Hidden);
});

it('tampered story code: another couple\'s story can\'t be answered or withdrawn (404), nothing changes', function (): void {
    $bride = bride();
    $groom = groom();
    Interest::factory()->accepted()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);
    $this->actingAs($bride, 'web');
    mpTab()->set('showStoryForm', true)->set('partnerCode', $groom->profile->code)->set('weddingDate', '2026-10-12')
        ->set('quote', 'Two families, one Oppam interest.')->set('storyBody', str_repeat('A long and happy story. ', 4))->call('submitStory');
    $code = SuccessStory::query()->sole()->code;

    $this->actingAs(groom('+919800000022'), 'web');
    mpTab()->call('answerStory', $code, true)->assertNotFound();
    mpTab()->call('withdrawStory', $code)->assertNotFound();
    expect(SuccessStory::query()->sole()->consent_b_at)->toBeNull()->and(SuccessStory::query()->sole()->withdrawn_at)->toBeNull();
});

it('close: a wrong password is a field error; the right one signs out and lands on home', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');

    mpTab()->set('closeReason', ClosureReason::NotInterested->value)->set('closePassword', 'wrong-pass1')->call('closeAccount')
        ->assertHasErrors(['closePassword'])->assertSet('closePassword', '');
    expect($bride->refresh()->trashed())->toBeFalse();

    mpTab()->set('closeReason', ClosureReason::NotInterested->value)->set('closePassword', TEST_MEMBER_PASSWORD)->call('closeAccount')
        ->assertRedirect(route('home'));
    expect(User::withTrashed()->findOrFail($bride->id)->trashed())->toBeTrue()->and(auth('web')->check())->toBeFalse();
});

it('in an admin support session every action is refused with a toast', function (): void {
    $bride = bride();
    $this->actingAs($bride, 'web');
    chatImpersonate($bride);

    mpTab()->call('hide')->assertDispatched('toast', type: 'danger');
    mpTab()->call('requestExport')->assertDispatched('toast', type: 'danger');
    mpTab()->set('closeReason', ClosureReason::NotInterested->value)->set('closePassword', TEST_MEMBER_PASSWORD)->call('closeAccount')
        ->assertDispatched('toast', type: 'danger');

    expect(statusOf($bride))->toBe(ProfileStatus::Active)->and($bride->refresh()->trashed())->toBeFalse();
});

it('the recovery window in the text follows A15 members.purge_after_days', function (): void {
    App\Models\Setting::factory()->keyed(App\Enums\SettingKey::MembersPurgeAfterDays, 45)->create();
    $this->actingAs(bride(), 'web');

    mpTab()->assertSee('You have 45 days to change your mind')->assertDontSee('30 days');
});

it('the partner sees the whole story, escaped; a story from a pair blocked since is not shown', function (): void {
    $bride = bride();
    $groom = groom();
    Interest::factory()->accepted()->create(['from_profile_id' => $groom->profile->id, 'to_profile_id' => $bride->profile->id]);
    $this->actingAs($bride, 'web');
    mpTab()->set('showStoryForm', true)->set('partnerCode', $groom->profile->code)->set('weddingDate', '2026-10-12')
        ->set('quote', 'Two families, one Oppam interest.')
        ->set('storyBody', '<script>alert(1)</script> '.str_repeat('A long and happy story. ', 3))->call('submitStory');

    $this->actingAs($groom, 'web');
    mpTab()->assertSee('A long and happy story.')->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');

    App\Models\Block::query()->forceCreate(['blocker_profile_id' => $bride->profile->id, 'blocked_profile_id' => $groom->profile->id]);
    mpTab()->assertDontSee('A long and happy story.')->assertDontSee('Yes, share our story');
});
