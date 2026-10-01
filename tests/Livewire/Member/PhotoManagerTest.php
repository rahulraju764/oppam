<?php

declare(strict_types=1);

use App\Enums\PhotoStatus;
use App\Livewire\Member\Onboarding\Wizard;
use App\Livewire\Member\Profile\PhotoManager;
use App\Models\Media;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

/*
| P1.4 — <livewire:member.profile.photo-manager />: upload (after the browser crop), statuses,
| reorder / primary, caption, delete, horoscope, the wizard's photo requirement, authorization.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
});

function photoManagerAs(User $user): mixed
{
    test()->actingAs($user, 'web');

    return Livewire::test(PhotoManager::class);
}

it('shows the empty state, the add button and the horoscope field', function (): void {
    photoManagerAs(memberThroughStep(5))
        ->assertOk()
        ->assertSee('No photos yet')
        ->assertSee('Add your profile photo')
        ->assertSeeHtml('id="photo-input"')
        ->assertSeeHtml('id="horoscope-input"');
});

it('uploads a cropped photo, labels it "Awaiting review" and tells the page', function (): void {
    $user = memberThroughStep(5);

    photoManagerAs($user)
        ->set('photo', UploadedFile::fake()->image('crop.jpg', 800, 1000))
        ->call('savePhoto')
        ->assertHasNoErrors()
        ->assertSee('Awaiting review')
        ->assertSee('Profile photo')
        ->assertSee('will appear on your profile once it has been reviewed')
        ->assertDispatched('photos-changed');

    expect(Media::query()->count())->toBe(1);
});

it('shows the reason under the field when a photo is refused', function (): void {
    photoManagerAs(memberThroughStep(5))
        ->set('photo', UploadedFile::fake()->image('tiny.jpg', 200, 200))
        ->call('savePhoto')
        ->assertHasErrors(['photo']);

    expect(Media::query()->count())->toBe(0);
});

it('makes a photo primary, saves a caption and deletes a photo', function (): void {
    $user = memberThroughStep(5);
    $first = addTestPhoto($user);
    $second = addTestPhoto($user);

    $component = photoManagerAs($user)
        ->call('movePhoto', (string) $second->uuid, 0)
        ->assertSee('Primary photo updated.')
        ->call('updateCaption', (string) $second->uuid, 'Onam 2025')
        ->assertSee('Caption saved.');

    expect(Media::query()->orderBy('order_column')->value('uuid'))->toBe((string) $second->uuid)
        ->and($second->refresh()->caption)->toBe('Onam 2025');

    $component->call('deletePhoto', (string) $first->uuid)->assertSee('Photo deleted.');
    expect(Media::query()->count())->toBe(1);
});

it('shows a rejected photo with its reason to the owner', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user)->forceFill(['moderation_status' => PhotoStatus::Rejected, 'rejection_reason' => 'Face not clearly visible.'])->save();

    photoManagerAs($user)->assertSee('Not approved')->assertSee('Face not clearly visible.');
});

it('IDOR: another member\'s photo uuid is a 404 and nothing changes', function (): void {
    $victim = memberThroughStep(5);
    $photo = addTestPhoto($victim);

    photoManagerAs(memberThroughStep(5))->call('deletePhoto', (string) $photo->uuid)->assertNotFound();

    expect(Media::query()->whereKey($photo->id)->exists())->toBeTrue();
});

it('hides the add button at 10 photos', function (): void {
    $user = memberThroughStep(5);
    foreach (range(1, 10) as $i) {
        addTestPhoto($user, 500, 500);
    }

    photoManagerAs($user)->assertDontSee('Add another photo');
});

it('uploads and offers a signed link to the horoscope', function (): void {
    $user = memberThroughStep(5);

    photoManagerAs($user)
        ->set('horoscope', UploadedFile::fake()->createWithContent('h.pdf', "%PDF-1.4\n%%EOF\n"))
        ->assertHasNoErrors()
        ->assertSee('Horoscope saved.')
        ->assertSee('View your horoscope')
        ->assertSeeHtml('signature=');
});

it('404s for a broker login', function (): void {
    test()->actingAs(User::factory()->broker()->create(), 'web');

    Livewire::test(PhotoManager::class)->assertNotFound();
});

it('wizard step 6 shows the photo manager and refuses to submit without a photo', function (): void {
    $user = memberThroughStep(5);
    test()->actingAs($user, 'web');

    Livewire::test(Wizard::class, ['step' => 6])
        ->assertSeeLivewire(PhotoManager::class)
        ->set('about.about', 'I am a software engineer in Kochi who loves music, travel and time with family.')
        ->call('submit')
        ->assertHasErrors(['submit'])
        ->assertSee('at least one photo');
});
