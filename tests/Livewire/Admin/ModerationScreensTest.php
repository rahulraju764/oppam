<?php

declare(strict_types=1);

use App\Actions\Profile\SubmitProfile;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Livewire\Admin\Moderation\EditedFieldsQueue;
use App\Livewire\Admin\Moderation\Escalations;
use App\Livewire\Admin\Moderation\PhotoQueueGrid;
use App\Livewire\Admin\Moderation\ProfileQueue;
use App\Livewire\Admin\Moderation\ProfileReview;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Models\User;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P1.6 — A04 screens: queue, review (claim on open, read-only when taken), photo grid batch,
| edited fields, escalations; permissions and locked props.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
});

function waitingProfile(): User
{
    $user = memberThroughStep(6);
    app(SubmitProfile::class)->handle($user, $user->profile()->firstOrFail());

    return $user->refresh();
}

function asAdmin(AdminUser $admin): void
{
    test()->actingAs($admin, 'admin');
}

it('lists waiting profiles, paid lane first, and forbids admins without moderation.view', function (): void {
    $standard = waitingProfile();
    $paid = memberThroughStep(6);
    $paid->profile->forceFill(['is_premium' => true])->save();
    app(SubmitProfile::class)->handle($paid->refresh(), $paid->profile()->firstOrFail());

    asAdmin(adminWithRole('moderator'));
    Livewire::test(ProfileQueue::class)
        ->assertOk()
        ->assertSeeInOrder([$paid->profile->code, $standard->profile->code])
        ->set('priorityOnly', true)
        ->assertSee($paid->profile->code)
        ->assertDontSee($standard->profile->code);

    asAdmin(adminWithRole('finance'));
    Livewire::test(ProfileQueue::class)->assertForbidden();
});

it('opening a review claims it; a second moderator sees it read-only', function (): void {
    $user = waitingProfile();
    $first = adminWithRole('moderator');
    $second = adminWithRole('moderator');

    asAdmin($first);
    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])->assertOk()->assertSee('Approve');
    expect(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->sole()->claimed_by_admin_id)->toBe($first->id);

    asAdmin($second);
    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])
        ->assertSee('is reviewing this profile right now')
        ->assertDontSee('Escalate to super admin');
});

it('approves from the review page and returns to the queue', function (): void {
    $user = waitingProfile();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])
        ->call('approve')
        ->assertRedirect(route('admin.moderation.profiles'));

    expect($user->profile()->firstOrFail()->status)->toBe(ProfileStatus::Active);
});

it('rejecting needs a reason; request changes sends the member to the chosen step', function (): void {
    $user = waitingProfile();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])
        ->call('reject')->assertHasErrors(['reason'])
        ->set('reason', 'INCOMPLETE')->set('note', 'Add your family details.')->set('step', '3')
        ->call('requestChanges')
        ->assertRedirect(route('admin.moderation.profiles'));

    expect(ModerationItem::query()->ofType(ModerationItemType::ProfileNew)->sole()->status)->toBe(ModerationStatus::ChangesRequested);
});

it('shows the automatic flags on the review page', function (): void {
    $user = memberThroughStep(6);
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user, $user->profile()->firstOrFail(),
        aboutData(['about' => 'Please WhatsApp me on 98470 12345, I would love to talk to you soon.']));
    app(SubmitProfile::class)->handle($user->refresh(), $user->profile()->firstOrFail());
    asAdmin(adminWithRole('moderator'));

    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])->assertSee('Possible contact details in about me.');
});

it('404s a profile that is not waiting, and refuses tampered locked props', function (): void {
    asAdmin(adminWithRole('moderator'));
    Livewire::test(ProfileReview::class, ['profile' => 'OPM99999999'])->assertNotFound();

    $user = waitingProfile();
    expect(fn () => Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])->set('itemId', 'x'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('the photo grid claims its batch and applies keyboard decisions', function (): void {
    $user = memberThroughStep(5);
    $keep = addTestPhoto($user);
    $drop = addTestPhoto($user);
    $items = ModerationItem::query()->ofType(ModerationItemType::Photo)->get()->keyBy('subject_id');
    asAdmin(adminWithRole('moderator'));

    Livewire::test(PhotoQueueGrid::class)
        ->assertOk()
        ->assertSee($user->profile->code)
        ->call('decide', [$items[$keep->uuid]->id => 'approve', $items[$drop->uuid]->id => 'reject', 'not-an-item' => 'approve'])
        ->assertDispatched('toast');

    expect($keep->refresh()->moderation_status)->toBe(PhotoStatus::Approved)
        ->and($drop->refresh()->moderation_status)->toBe(PhotoStatus::Rejected);
});

it('the photo grid leaves out photos another moderator holds', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    $item->forceFill(['claimed_by_admin_id' => adminWithRole('moderator')->id, 'claimed_until' => now()->addMinutes(10)])->save();

    asAdmin(adminWithRole('moderator'));
    Livewire::test(PhotoQueueGrid::class)->assertSee('All caught up');
});

it('a read-only admin can look at the photo grid but not decide', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    asAdmin(adminWithRole('read_only'));

    Livewire::test(PhotoQueueGrid::class)->assertOk()->assertDontSee('Apply decisions')
        ->call('decide', [$item->id => 'approve'])->assertForbidden();
});

it('the edited-fields queue shows old and new text and applies an approval', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $old = $user->profile->about;
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'A new about me text for review, comfortably longer than fifty characters.']));
    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(EditedFieldsQueue::class)
        ->assertSee($old)
        ->assertSee('A new about me text for review')
        ->call('approve', $item->id, $item->fieldsFingerprint());

    expect($user->profile()->firstOrFail()->about)->toBe('A new about me text for review, comfortably longer than fifty characters.');
});

it('escalations: moderators can look, only a super admin decides', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    $item->forceFill(['status' => ModerationStatus::Escalated, 'reason_note' => 'Possible celebrity photo.'])->save();

    asAdmin(adminWithRole('moderator'));
    Livewire::test(Escalations::class)->assertSee('Possible celebrity photo.')->assertSee('Only a super admin can decide')
        ->call('approve', $item->id)->assertDispatched('toast', type: 'error');
    expect($item->refresh()->status)->toBe(ModerationStatus::Escalated);

    asAdmin(adminWithRole('super_admin'));
    Livewire::test(Escalations::class)->call('approve', $item->id);
    expect($item->refresh()->status)->toBe(ModerationStatus::Approved);
});

it('the member sees the moderator\'s note and an "Edit & resubmit" link to the right step', function (): void {
    $user = waitingProfile();
    asAdmin(adminWithRole('moderator'));
    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])
        ->set('reason', 'INCOMPLETE')->set('note', 'Please add your family details.')->set('step', '3')
        ->call('requestChanges');

    $this->seed(Database\Seeders\PlansSeeder::class);
    test()->actingAs($user->refresh(), 'web');
    Livewire::test(App\Livewire\Member\Profile\MyProfile::class)
        ->assertSee('Please add your family details.')
        ->assertSeeHtml(route('member.onboarding', ['step' => 3]));
});

it('the edited-fields queue refuses an approval of text that changed since it was shown', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $old = $user->profile->about;
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'What the moderator saw on screen, comfortably longer than fifty characters.']));
    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(EditedFieldsQueue::class)
        ->call('approve', $item->id, hash('sha256', 'something else'))
        ->assertDispatched('toast', type: 'error');

    expect($user->profile()->firstOrFail()->about)->toBe($old);
});

it('escalated edits show old and new text to the super admin, who decides on what was shown', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $old = $user->profile->about;
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'An escalated about me text for review, longer than fifty characters.']));
    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();
    $item->forceFill(['status' => ModerationStatus::Escalated, 'reason_note' => 'Second opinion please.'])->save();
    asAdmin(adminWithRole('super_admin'));

    Livewire::test(Escalations::class)
        ->assertSee($old)
        ->assertSee('An escalated about me text for review')
        ->call('approve', $item->id, $item->fieldsFingerprint())
        ->assertDispatched('toast', type: 'success');

    expect($user->profile()->firstOrFail()->about)->toStartWith('An escalated about me text');
});

it('the photo grid needs a note for "Other" rejections and decides nothing without it', function (): void {
    $user = memberThroughStep(5);
    $photo = addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(PhotoQueueGrid::class)
        ->set('reason', 'OTHER')
        ->call('decide', [$item->id => 'reject'])
        ->assertHasErrors(['note']);

    expect($photo->refresh()->moderation_status)->toBe(PhotoStatus::Pending);
});

it('the photo grid claims on load, not on every render; new photos appear on "Check again"', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user);
    asAdmin(adminWithRole('moderator'));
    $grid = Livewire::test(PhotoQueueGrid::class);
    $first = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    $until = $first->claimed_until;

    addTestPhoto($user);
    $this->travel(2)->minutes();
    $grid->call('$refresh');

    expect($first->refresh()->claimed_until->equalTo($until))->toBeTrue()
        ->and(ModerationItem::query()->ofType(ModerationItemType::Photo)->whereNotNull('claimed_by_admin_id')->count())->toBe(1);

    $grid->call('refreshBatch');
    expect(ModerationItem::query()->ofType(ModerationItemType::Photo)->whereNotNull('claimed_by_admin_id')->count())->toBe(2);
});

it('the review page shows duplicate-photo flags only for photos still waiting', function (): void {
    addTestPhoto(memberThroughStep(5), 600, 800);
    $user = memberThroughStep(6);
    $copy = addTestPhoto($user, 600, 800);   // same picture → flagged at upload
    $photoItem = ModerationItem::query()->ofType(ModerationItemType::Photo)->where('subject_id', $copy->uuid)->sole();
    app(SubmitProfile::class)->handle($user->refresh(), $user->profile()->firstOrFail());
    asAdmin(adminWithRole('moderator'));
    $message = (string) (collect($photoItem->fields['flags'] ?? [])->firstWhere('code', 'duplicate_photo')['message'] ?? '');
    expect($message)->not->toBe('');

    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])->assertSee($message);

    // Every photo of this member is decided (memberThroughStep's own photo is the same picture too).
    ModerationItem::query()->ofType(ModerationItemType::Photo)->where('profile_id', $user->profile->id)
        ->update(['status' => ModerationStatus::Rejected, 'decided_at' => now()]);
    Livewire::test(ProfileReview::class, ['profile' => $user->profile->code])->assertDontSee($message);
});

it('every moderation page sends a guest to the admin sign-in', function (string $path): void {
    $this->get(adminUrl($path))->assertRedirect(route('admin.login'));
})->with(['/moderation/profiles', '/moderation/profiles/OPM12370', '/moderation/photos', '/moderation/edits', '/moderation/escalations']);

it('P1.6 carry-over: the grid tells the browser a batch was applied (marks are cleared only then)', function (): void {
    $user = memberThroughStep(5);
    addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    asAdmin(adminWithRole('moderator'));

    Livewire::test(PhotoQueueGrid::class)
        ->set('reason', 'OTHER')->call('decide', [$item->id => 'reject'])->assertNotDispatched('photo-batch-applied')
        ->set('note', 'Please upload a clearer photo.')->call('decide', [$item->id => 'reject'])->assertDispatched('photo-batch-applied');
});

it('P1.6 carry-over: an edit escalated meanwhile gives the moderator a toast, not an error page', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'An about me text escalated while on screen, longer than fifty characters.']));
    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();
    asAdmin(adminWithRole('moderator'));
    $queue = Livewire::test(EditedFieldsQueue::class);

    $item->forceFill(['status' => ModerationStatus::Escalated])->save();

    $queue->call('approve', $item->id, $item->fieldsFingerprint())->assertDispatched('toast', type: 'error');
    expect($item->refresh()->status)->toBe(ModerationStatus::Escalated);
});
