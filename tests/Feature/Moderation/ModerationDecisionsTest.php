<?php

declare(strict_types=1);

use App\Actions\Moderation\ApproveProfile;
use App\Actions\Moderation\ClaimModerationItem;
use App\Actions\Moderation\DecidePhoto;
use App\Actions\Moderation\DecideProfileEdit;
use App\Actions\Moderation\EscalateModerationItem;
use App\Actions\Moderation\RejectProfile;
use App\Actions\Profile\SubmitProfile;
use App\Domain\Profile\PendingTextEdits;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Enums\ProfileStatus;
use App\Enums\RejectReason;
use App\Enums\WizardStep;
use App\Events\Admin\AdminQueueCountChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AuditLog;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\User;
use App\Notifications\Profile\ProfileApproved;
use App\Notifications\Profile\ProfileContentRejected;
use App\Notifications\Profile\ProfileNeedsChanges;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| P1.6 — A04 decisions: approve / reject / request changes / escalate, the 15-minute claim, the
| super-admin rule for escalations, photos, edited fields, underage auto-reject, audit rows.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
});

/** @return array{0: User, 1: ModerationItem} a member whose finished profile waits in the A04 queue */
function submitted(): array
{
    $user = memberThroughStep(6);
    $user->forceFill(['email' => 'member@example.com'])->save();
    $item = app(SubmitProfile::class)->handle($user, $user->profile()->firstOrFail());

    return [$user->refresh(), $item];
}

function audited(string $action): int
{
    return AuditLog::query()->where('action', $action)->count();
}

// ---- Approve / reject / request changes ----------------------------------------------------------

it('A04 approve: the profile goes live, published_at is set, the member is told, the decision is audited', function (): void {
    Notification::fake();
    Event::fake([AdminQueueCountChanged::class]);
    [$user, $item] = submitted();

    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    $profile = $user->profile()->firstOrFail();
    expect($profile->status)->toBe(ProfileStatus::Active)
        ->and($profile->published_at)->not->toBeNull()
        ->and($item->refresh()->status)->toBe(ModerationStatus::Approved)
        ->and($item->claimed_by_admin_id)->toBeNull()
        ->and(audited('moderation.profile_approved'))->toBe(1);

    Notification::assertSentTo($user, ProfileApproved::class);
    Event::assertDispatched(AdminQueueCountChanged::class, fn ($e) => $e->broadcastWith() === ['queue' => 'moderation.profiles', 'count' => 0]);
});

it('R-M02-1: approving again (after an edit & resubmit) keeps the FIRST publish date', function (): void {
    [$user, $item] = submitted();
    $first = now()->subMonth()->startOfSecond();
    $user->profile->forceFill(['published_at' => $first])->save();

    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    expect($user->profile()->firstOrFail()->published_at->equalTo($first))->toBeTrue();
});

it('R-M02-5 reject: REJECTED with the category message + note shown to the member; audited; emailed', function (): void {
    Notification::fake();
    [$user, $item] = submitted();

    app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::ContactInText, 'Remove the number from About me.');

    $profile = $user->profile()->firstOrFail();
    expect($profile->status)->toBe(ProfileStatus::Rejected)
        ->and($item->refresh()->status)->toBe(ModerationStatus::Rejected)
        ->and($item->reason_category)->toBe('CONTACT_IN_TEXT')
        ->and(ModerationItem::latestDecisionNote($profile))->toBe(RejectReason::ContactInText->memberMessage().' Remove the number from About me.')
        ->and(audited('moderation.profile_rejected'))->toBe(1);

    Notification::assertSentTo($user, ProfileNeedsChanges::class, fn (ProfileNeedsChanges $n) => $n->reason === RejectReason::ContactInText);
});

it('the reason "Other" needs a note', function (): void {
    [, $item] = submitted();

    expect(fn () => app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::Other, '  '))
        ->toThrow(ValidationException::class);
});

it('request changes: the member is sent back to the named wizard step', function (): void {
    [$user, $item] = submitted();

    app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::Incomplete, 'Please add your family details.', WizardStep::Family);

    $profile = $user->profile()->firstOrFail();
    expect($item->refresh()->status)->toBe(ModerationStatus::ChangesRequested)
        ->and($profile->status)->toBe(ProfileStatus::Rejected)
        ->and(ModerationItem::latestDecisionStep($profile))->toBe(WizardStep::Family)
        ->and(audited('moderation.profile_changes_requested'))->toBe(1);
});

it('a member can resubmit after a rejection and the profile re-enters the queue', function (): void {
    [$user, $item] = submitted();
    app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::Incomplete, null);

    $again = app(SubmitProfile::class)->handle($user->refresh(), $user->profile()->firstOrFail());

    expect($again->status)->toBe(ModerationStatus::Open)->and($again->id)->not->toBe($item->id);
});

// ---- Claims ----------------------------------------------------------------------------------------

it('A04 claim: two moderators can\'t hold or decide the same item', function (): void {
    [, $item] = submitted();
    $first = adminWithRole('moderator');
    $second = adminWithRole('moderator');

    app(ClaimModerationItem::class)->handle($first, $item);

    expect(fn () => app(ClaimModerationItem::class)->handle($second, $item))->toThrow(ModerationItemUnavailable::class, 'Another moderator')
        ->and(fn () => app(ApproveProfile::class)->handle($second, $item->refresh()))->toThrow(ModerationItemUnavailable::class);

    expect($item->refresh()->status)->toBe(ModerationStatus::Open)
        ->and($item->claimed_by_admin_id)->toBe($first->id);
});

it('a claim lapses after 15 minutes and can be released early', function (): void {
    [, $item] = submitted();
    $first = adminWithRole('moderator');
    $second = adminWithRole('moderator');
    app(ClaimModerationItem::class)->handle($first, $item);

    $this->travel(16)->minutes();
    app(ClaimModerationItem::class)->handle($second, $item->refresh());
    expect($item->refresh()->claimed_by_admin_id)->toBe($second->id);

    app(ClaimModerationItem::class)->release($second, $item);
    expect($item->refresh()->claimed_by_admin_id)->toBeNull();
});

it('a decided item can\'t be decided again', function (): void {
    [, $item] = submitted();
    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    expect(fn () => app(RejectProfile::class)->handle(adminWithRole('moderator'), $item->refresh(), RejectReason::Incomplete, null))
        ->toThrow(ModerationItemUnavailable::class, 'already been decided');
});

// ---- Permissions & escalation --------------------------------------------------------------------

it('needs moderation.act to decide: read-only and other roles are refused', function (string $role): void {
    [, $item] = submitted();

    expect(fn () => app(ApproveProfile::class)->handle(adminWithRole($role), $item))->toThrow(AuthorizationException::class)
        ->and($item->refresh()->status)->toBe(ModerationStatus::Open);
})->with(['read_only', 'finance', 'verification_officer']);

it('escalate: needs a reason, leaves the queue, and only a super admin can then decide it', function (): void {
    [$user, $item] = submitted();
    $moderator = adminWithRole('moderator');

    expect(fn () => app(EscalateModerationItem::class)->handle($moderator, $item, ' '))->toThrow(ValidationException::class);

    app(EscalateModerationItem::class)->handle($moderator, $item, 'Photos look like a celebrity.');
    expect($item->refresh()->status)->toBe(ModerationStatus::Escalated)
        ->and(audited('moderation.escalated'))->toBe(1)
        ->and(ModerationItem::pendingCount(ModerationItemType::ProfileNew))->toBe(1);

    expect(fn () => app(ApproveProfile::class)->handle($moderator, $item))->toThrow(AuthorizationException::class);

    app(ApproveProfile::class)->handle(adminWithRole('super_admin'), $item);
    expect($user->profile()->firstOrFail()->status)->toBe(ProfileStatus::Active);
});

// ---- Underage --------------------------------------------------------------------------------------

it('A04 underage: a submitted underage profile is rejected automatically, and can never be approved', function (): void {
    Notification::fake();
    $user = memberThroughStep(6);
    $user->forceFill(['email' => 'young@example.com'])->save();
    // The wizard blocks this; simulate data that slipped through (e.g. an import, P7).
    $user->profile->forceFill(['dob' => now()->subYears(16)->toDateString()])->save();

    $item = app(SubmitProfile::class)->handle($user->refresh(), $user->profile()->firstOrFail());

    expect($item->refresh()->status)->toBe(ModerationStatus::Rejected)
        ->and($item->reason_category)->toBe('UNDERAGE')
        ->and($user->profile()->firstOrFail()->status)->toBe(ProfileStatus::Rejected)
        ->and(audited('moderation.profile_auto_rejected'))->toBe(1);

    Notification::assertSentTo($user, ProfileNeedsChanges::class);

    // Even if item and profile were put back by hand, an underage profile can't be approved.
    $item->forceFill(['status' => ModerationStatus::Open])->save();
    $user->profile->forceFill(['status' => ProfileStatus::PendingReview])->save();
    expect(fn () => app(ApproveProfile::class)->handle(adminWithRole('super_admin'), $item))->toThrow(InvalidArgumentException::class);
});

// ---- Photos -----------------------------------------------------------------------------------------

it('A04 photo: approve makes it visible to others; reject labels it for the owner and emails the reason', function (): void {
    Notification::fake();
    $user = memberThroughStep(5);
    $user->forceFill(['email' => 'member@example.com'])->save();
    $good = addTestPhoto($user);
    $bad = addTestPhoto($user);
    $items = ModerationItem::query()->ofType(ModerationItemType::Photo)->get()->keyBy('subject_id');
    $moderator = adminWithRole('moderator');

    app(DecidePhoto::class)->handle($moderator, $items[$good->uuid], true);
    app(DecidePhoto::class)->handle($moderator, $items[$bad->uuid], false, RejectReason::Inappropriate, 'Group photo.');

    expect($good->refresh()->moderation_status)->toBe(PhotoStatus::Approved)
        ->and($bad->refresh()->moderation_status)->toBe(PhotoStatus::Rejected)
        ->and($bad->rejection_reason)->toBe(RejectReason::Inappropriate->memberMessage())
        ->and(audited('moderation.photo_approved'))->toBe(1)
        ->and(audited('moderation.photo_rejected'))->toBe(1);

    Notification::assertSentToTimes($user, ProfileContentRejected::class, 1);
});

it('a rejected caption is cleared; the approved photo stays', function (): void {
    $user = memberThroughStep(5);
    $photo = addTestPhoto($user);
    $photo->forceFill(['moderation_status' => PhotoStatus::Approved])->save();
    ModerationItem::query()->delete();
    app(App\Actions\Profile\Photos\UpdatePhotoCaption::class)->handle($user, $user->profile()->firstOrFail(), (string) $photo->uuid, 'WhatsApp me');

    app(DecidePhoto::class)->handle(adminWithRole('moderator'), ModerationItem::query()->sole(), false, RejectReason::ContactInText);

    expect($photo->refresh()->caption)->toBeNull()
        ->and($photo->moderation_status)->toBe(PhotoStatus::Approved);
});

it('a photo deleted while waiting just closes its item', function (): void {
    $user = memberThroughStep(5);
    $photo = addTestPhoto($user);
    $item = ModerationItem::query()->sole();
    Media::query()->whereKey($photo->id)->delete();   // bypass the Action, which would also remove the item

    app(DecidePhoto::class)->handle(adminWithRole('moderator'), $item, true);

    expect($item->refresh()->status)->toBe(ModerationStatus::Approved);
});

// ---- Edited fields (R-M02-4) ------------------------------------------------------------------------

it('R-M02-4 approve: the held text is written to the live profile', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'A freshly rewritten about me that is easily longer than fifty characters.']));
    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();

    app(DecideProfileEdit::class)->handle(adminWithRole('moderator'), $item, $item->fieldsFingerprint(), true);

    $profile = $user->profile()->firstOrFail();
    expect($profile->about)->toBe('A freshly rewritten about me that is easily longer than fifty characters.')
        ->and(app(PendingTextEdits::class)->pending($profile))->toBe([])
        ->and(audited('moderation.edit_approved'))->toBe(1);
});

it('R-M02-4 reject: the old text stays and the member is told', function (): void {
    Notification::fake();
    $user = memberThroughStep(6);
    $user->forceFill(['email' => 'member@example.com'])->save();
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $old = $user->profile->about;
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'Call me on 98470 12345 any time, I am waiting to hear from you soon!']));

    $item = ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole();
    app(DecideProfileEdit::class)->handle(adminWithRole('moderator'), $item, $item->fieldsFingerprint(), false, RejectReason::ContactInText);

    expect($user->profile()->firstOrFail()->about)->toBe($old);
    Notification::assertSentTo($user, ProfileContentRejected::class);
});

it('approving an edit item can only ever write the moderated text columns (tampered fields are ignored)', function (): void {
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    $item = new ModerationItem;
    $item->forceFill([
        'type' => ModerationItemType::ProfileEdit, 'profile_id' => $user->profile->id, 'status' => ModerationStatus::Open,
        'submitted_at' => now(), 'fields' => ['profiles.status' => 'SUSPENDED', 'profiles.is_premium' => '1', 'profiles.about' => 'An allowed new about me text, comfortably beyond fifty characters.'],
    ])->save();

    app(DecideProfileEdit::class)->handle(adminWithRole('moderator'), $item, $item->fieldsFingerprint(), true);

    $profile = $user->profile()->firstOrFail();
    expect($profile->status)->toBe(ProfileStatus::Active)
        ->and($profile->is_premium)->toBeFalse()
        ->and($profile->about)->toStartWith('An allowed new about me');
});

it('P1.6 review Major: a profile suspended / hidden while waiting is never flipped; its item is closed', function (ProfileStatus $meanwhile): void {
    [$user, $item] = submitted();
    $user->profile->forceFill(['status' => $meanwhile])->save();

    expect(fn () => app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item))
        ->toThrow(ModerationItemUnavailable::class, 'no longer waiting');

    expect($user->profile()->firstOrFail()->status)->toBe($meanwhile)
        ->and($item->refresh()->status->isPending())->toBeFalse()
        ->and($item->reason_category)->toBe('STALE')
        ->and($item->decided_by_admin_id)->toBeNull()
        ->and(audited('moderation.item_closed_stale'))->toBe(1)
        ->and(ModerationItem::pendingCount(ModerationItemType::ProfileNew))->toBe(0);

    expect(fn () => app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::Incomplete, null))
        ->toThrow(ModerationItemUnavailable::class);
})->with([ProfileStatus::Suspended, ProfileStatus::Hidden, ProfileStatus::Active]);

it('a soft-deleted profile\'s item is closed instead of staying in the queue', function (): void {
    [$user, $item] = submitted();
    $user->profile->delete();

    expect(fn () => app(RejectProfile::class)->handle(adminWithRole('moderator'), $item, RejectReason::Incomplete, null))
        ->toThrow(ModerationItemUnavailable::class);

    expect(ModerationItem::pendingCount(ModerationItemType::ProfileNew))->toBe(0)
        ->and(audited('moderation.item_closed_stale'))->toBe(1);
});

it('the photo grid claims its whole batch with one update, skipping items held by others and escalated ones', function (): void {
    $user = memberThroughStep(5);
    foreach (range(1, 3) as $i) {
        addTestPhoto($user);
    }
    [$a, $b, $c] = ModerationItem::query()->ofType(ModerationItemType::Photo)->orderBy('id')->get()->all();
    $other = adminWithRole('moderator');
    $b->forceFill(['claimed_by_admin_id' => $other->id, 'claimed_until' => now()->addMinutes(5)])->save();
    $c->forceFill(['status' => ModerationStatus::Escalated])->save();
    $me = adminWithRole('moderator');

    $mine = app(ClaimModerationItem::class)->many($me, [(string) $a->id, (string) $b->id, (string) $c->id]);

    expect($mine)->toBe([(string) $a->id])
        ->and($b->refresh()->claimed_by_admin_id)->toBe($other->id);
});

it('stores the duplicate-photo flag on the PHOTO item at upload (not recomputed per render)', function (): void {
    addTestPhoto(memberThroughStep(5), 600, 800);
    addTestPhoto(memberThroughStep(5), 600, 800);   // the same generated picture on another profile

    $flags = ModerationItem::query()->ofType(ModerationItemType::Photo)->latest('id')->first()->fields['flags'] ?? [];

    expect(array_column($flags, 'code'))->toContain('duplicate_photo');
});

// ---- P1.6 review round 2 ------------------------------------------------------------------------

/** A live profile with one held about-me edit; returns the member and the PROFILE_EDIT item. */
function liveWithEdit(string $about): array
{
    $user = memberThroughStep(6);
    $user->profile->forceFill(['status' => ProfileStatus::Active, 'published_at' => now()])->save();
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(), aboutData(['about' => $about]));

    return [$user, ModerationItem::query()->ofType(ModerationItemType::ProfileEdit)->sole()];
}

it('P1.6 review Major (R-M02-4): text the member changed after the moderator looked is never approved', function (): void {
    [$user, $item] = liveWithEdit('The text the moderator actually read, longer than fifty characters easily.');
    $old = $user->profile()->firstOrFail()->about;
    $seen = $item->fieldsFingerprint();

    // The member edits again while the moderator is reading: the same item now holds new text.
    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'Now with my number 98470 12345 added after the moderator looked at it.']));

    expect(fn () => app(DecideProfileEdit::class)->handle(adminWithRole('moderator'), $item->refresh(), $seen, true))
        ->toThrow(ModerationItemUnavailable::class, 'changed this text');

    expect($user->profile()->firstOrFail()->about)->toBe($old)
        ->and($item->refresh()->status)->toBe(ModerationStatus::Open)
        ->and($item->fields['profiles.about'])->toStartWith('Now with my number')
        ->and(audited('moderation.edit_approved'))->toBe(0);
});

it('a member edit never pulls an escalated edit item back into the ordinary queue', function (): void {
    [$user, $item] = liveWithEdit('A first rewritten about me text that is longer than fifty characters.');
    $item->forceFill(['status' => ModerationStatus::Escalated, 'reason_note' => 'Unsure.'])->save();

    app(App\Actions\Profile\SaveAboutDetails::class)->handle($user->refresh(), $user->profile()->firstOrFail(),
        aboutData(['about' => 'A second rewritten about me text that is longer than fifty characters.']));

    expect($item->refresh()->status)->toBe(ModerationStatus::Escalated)
        ->and($item->fields['profiles.about'])->toStartWith('A second rewritten');
    expect(fn () => app(DecideProfileEdit::class)->handle(adminWithRole('moderator'), $item, $item->fieldsFingerprint(), true))
        ->toThrow(AuthorizationException::class);
});

it('rejecting an older caption item never clears the newer caption', function (): void {
    $user = memberThroughStep(5);
    $photo = addTestPhoto($user);
    $photo->forceFill(['moderation_status' => PhotoStatus::Approved])->save();
    ModerationItem::query()->delete();
    $caption = App\Actions\Profile\Photos\UpdatePhotoCaption::class;
    app($caption)->handle($user, $user->profile()->firstOrFail(), (string) $photo->uuid, 'First caption');
    $older = ModerationItem::query()->sole();
    app($caption)->handle($user, $user->profile()->firstOrFail(), (string) $photo->uuid, 'Second caption');

    app(DecidePhoto::class)->handle(adminWithRole('moderator'), $older, false, RejectReason::Inappropriate);

    expect($photo->refresh()->caption)->toBe('Second caption')
        ->and($older->refresh()->status)->toBe(ModerationStatus::Rejected);
});

it('photo and edit decisions validate the note: "Other" needs one, 1000 characters at most', function (): void {
    $user = memberThroughStep(5);
    $photo = addTestPhoto($user);
    $item = ModerationItem::query()->ofType(ModerationItemType::Photo)->sole();
    $moderator = adminWithRole('moderator');

    expect(fn () => app(DecidePhoto::class)->handle($moderator, $item, false, RejectReason::Other, '  '))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(DecidePhoto::class)->handle($moderator, $item, false, RejectReason::Inappropriate, str_repeat('a', 1001)))
        ->toThrow(ValidationException::class);
    expect($item->refresh()->status)->toBe(ModerationStatus::Open)
        ->and($photo->refresh()->moderation_status)->toBe(PhotoStatus::Pending);

    [, $edit] = liveWithEdit('Another rewritten about me text that is longer than fifty characters.');
    expect(fn () => app(DecideProfileEdit::class)->handle($moderator, $edit, $edit->fieldsFingerprint(), false, RejectReason::Other, null))
        ->toThrow(ValidationException::class);
    expect($edit->refresh()->status)->toBe(ModerationStatus::Open);
});
