<?php

declare(strict_types=1);

use App\Enums\VerificationStatus;
use App\Livewire\Admin\Members\Tabs\VerificationTab;
use App\Livewire\Admin\Verification\Queue;
use App\Livewire\Admin\Verification\Review;
use App\Models\AdminUser;
use App\Models\AuditLog;
use App\Models\VerificationRequest;
use App\Queries\Admin\WorkQueues;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P6.1 — A05 screens: queue (paid first, aging colours, lists, live listeners), review (claim on
| open, read-only when held, decisions, viewer on demand, locked props), A03 Verification tab
| (force re-verification) and the A02 / sidebar work-queue card.
*/

beforeEach(function (): void {
    seedAdminRoles();
    fakeVerificationDisk();
    Notification::fake();
});

function asOfficer(?AdminUser $admin = null): AdminUser
{
    $admin ??= adminWithRole('verification_officer');
    test()->actingAs($admin, 'admin');

    return $admin;
}

it('A05 queue: paid members first, then oldest; aging colours at 48 h / 72 h; flags shown', function (): void {
    $old = submitId(verificationMember());                              // 75 h old: red
    $this->travel(25)->hours();
    $paid = submitId(verificationMember(['is_premium' => true]));       // 50 h old: amber
    $this->travel(50)->hours();
    $fresh = submitId(verificationMember(), ['nameOnDocument' => 'Somebody Else']);

    asOfficer();
    Livewire::test(Queue::class)
        ->assertOk()
        ->assertSeeInOrder([$paid->profile->code, $old->profile->code, $fresh->profile->code])
        ->assertSee('Over 72 h')       // the oldest: red
        ->assertSee('Over 48 h')       // the paid one: amber
        ->assertSee('On time')
        ->assertSee('Name differs from the profile')
        ->assertSee('XXXX XXXX 0124')
        ->assertDontSee('2345 6789');
});

it('A05 queue lists: escalated and decided; empty state; live on .queue.count / .queue.claim', function (): void {
    asOfficer();
    Livewire::test(Queue::class)->assertSee('All caught up');

    $escalated = submitId(verificationMember());
    $escalated->forceFill(['status' => VerificationStatus::Escalated, 'escalation_note' => 'Second look'])->save();
    $decided = submitId(verificationMember());
    $decided->forceFill(['status' => VerificationStatus::Approved, 'decided_at' => now()])->save();

    $component = Livewire::test(Queue::class)
        ->assertDontSee($escalated->profile->code)
        ->call('show', 'escalated')->assertSee($escalated->profile->code)->assertDontSee($decided->profile->code)
        ->call('show', 'decided')->assertSee($decided->profile->code)->assertSee('Approved')
        ->call('show', 'nonsense')->assertSet('list', 'waiting');

    expect($component->instance()->getListeners())->toHaveKeys(['echo-private:admin.queues,.queue.count', 'echo-private:admin.queues,.queue.claim']);
});

it('needs verification.queue.view (finance, moderator: 403); read_only may look but not decide', function (): void {
    $request = submitId(verificationMember());

    foreach (['finance', 'moderator'] as $role) {
        asOfficer(adminWithRole($role));
        Livewire::test(Queue::class)->assertForbidden();
        Livewire::test(Review::class, ['profile' => $request->profile->code])->assertForbidden();
    }

    asOfficer(adminWithRole('read_only'));
    Livewire::test(Queue::class)->assertOk()->assertSee($request->profile->code);
    Livewire::test(Review::class, ['profile' => $request->profile->code])
        ->assertSee('You can view this request but not decide it.')
        ->assertDontSee('View documents');
    expect($request->refresh()->claimed_by_admin_id)->toBeNull();
});

it('A05 review: opening claims it; a second officer sees "being reviewed" and can’t decide or open documents', function (): void {
    $request = submitId(verificationMember());
    $first = asOfficer();
    Livewire::test(Review::class, ['profile' => $request->profile->code])->assertSee('Approve — add Verified badge')->assertSee('View documents');
    expect($request->refresh()->claimed_by_admin_id)->toBe($first->id);

    asOfficer(adminWithRole('verification_officer'));
    Livewire::test(Review::class, ['profile' => $request->profile->code])
        ->assertSee('is reviewing this request right now')
        ->assertDontSee('Approve — add Verified badge')
        ->assertDontSee('View documents')
        ->call('approve');
    expect($request->refresh()->status)->toBe(VerificationStatus::Pending);
});

it('A05 review: approve, reject (reason required), escalate (note required) and skip', function (): void {
    $code = fn (): string => submitId(verificationMember())->profile->code;
    asOfficer();

    $approved = $code();
    Livewire::test(Review::class, ['profile' => $approved])->call('approve')->assertRedirect(route('admin.verification.index'));
    expect(VerificationRequest::query()->whereHas('profile', fn ($q) => $q->where('code', $approved))->sole()->status)->toBe(VerificationStatus::Approved);

    $rejected = $code();
    Livewire::test(Review::class, ['profile' => $rejected])
        ->call('reject')->assertHasErrors('reason')
        ->set('reason', 'OTHER')->call('reject')->assertHasErrors('note')
        ->set('note', 'Please send the page with your photo.')->call('reject')->assertRedirect(route('admin.verification.index'));

    $escalated = $code();
    Livewire::test(Review::class, ['profile' => $escalated])
        ->call('escalate')->assertHasErrors('escalationNote')
        ->set('escalationNote', 'Looks edited near the date.')->call('escalate')->assertRedirect(route('admin.verification.index'));

    $skipped = $code();
    Livewire::test(Review::class, ['profile' => $skipped])->call('release')->assertRedirect(route('admin.verification.index'));
    expect(VerificationRequest::query()->whereHas('profile', fn ($q) => $q->where('code', $skipped))->sole()->claimed_by_admin_id)->toBeNull();
});

it('A05 viewer opens on demand for document.view only, audited; links bound to codes', function (): void {
    $request = submitId(verificationMember(), ['back' => idPdf()]);
    asOfficer();

    $component = Livewire::test(Review::class, ['profile' => $request->profile->code])
        ->assertDontSeeHtml('/document/front')
        ->call('viewDocuments')
        ->assertSeeHtml('/document/front')
        ->assertSeeHtml('pdfDocument(')
        ->assertSee('Access is logged');
    expect(AuditLog::query()->where('action', 'verification.documents_opened')->count())->toBe(1)
        ->and($component->get('documentUrls'))->toHaveKeys(['front', 'back']);

    $component->call('closeViewer')->assertSet('documentUrls', [])->assertDontSeeHtml('/document/front');

    // An approver without the document permission: no button, and the call is refused.
    $approver = adminWithRole('verification_officer');
    $approver->syncRoles([]);
    $approver->givePermissionTo(['verification.queue.view', 'verification.approve']);
    asOfficer($approver->refresh());
    $other = submitId(verificationMember());
    Livewire::test(Review::class, ['profile' => $other->profile->code])
        ->assertSee('needs the separate “view documents” permission')
        ->call('viewDocuments')
        ->assertDispatched('toast')
        ->assertSet('documentUrls', []);
});

it('rejects tampered locked props and unknown codes', function (string $property, mixed $value): void {
    $request = submitId(verificationMember());
    asOfficer();

    expect(fn () => Livewire::test(Review::class, ['profile' => $request->profile->code])->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'request id' => ['requestId', '01JAAAAAAAAAAAAAAAAAAAAAAA'],
    'code' => ['code', 'OPM99999'],
    'viewer urls' => ['documentUrls', ['front' => 'https://evil.test/x']],
    'viewer expiry' => ['viewerUntil', 9999999999],
]);

it('an unknown profile code, or a profile without a request, is a 404', function (): void {
    asOfficer();
    Livewire::test(Review::class, ['profile' => 'OPM99999'])->assertNotFound();
    Livewire::test(Review::class, ['profile' => verificationMember()->profile->code])->assertNotFound();
});

it('A03 Verification tab: history, and "force re-verification" for verification.approve only', function (): void {
    $member = verificationMember();
    $request = submitId($member);
    $request->forceFill(['status' => VerificationStatus::Approved, 'decided_at' => now()])->save();
    $member->profile->forceFill(['is_verified' => true])->save();

    asOfficer(adminWithRole('support'));
    Livewire::test(VerificationTab::class, ['code' => $member->profile->code])
        ->assertSee('XXXX XXXX 0124')->assertSee('Approved')->assertDontSee('Force re-verification')
        ->set('revokeReason', 'Trying anyway')->call('revoke')->assertForbidden();

    asOfficer();
    Livewire::test(VerificationTab::class, ['code' => $member->profile->code])
        ->assertSee('Force re-verification')
        ->set('revokeReason', 'Sibling’s card, reported by family.')->call('revoke')
        ->assertDispatched('toast')
        ->assertSee('Revoked');
    expect($member->profile->refresh()->is_verified)->toBeFalse();
});

it('A02 / sidebar: the ID verification queue card with its count and breach, for officers only', function (): void {
    submitId(verificationMember());
    $this->travel(73)->hours();
    submitId(verificationMember());

    $card = collect(app(WorkQueues::class)->for(adminWithRole('verification_officer')))->firstWhere('key', 'verification');
    expect($card?->count)->toBe(2)->and($card?->breached)->toBeTrue()->and($card?->routeName)->toBe('admin.verification.index')
        ->and(collect((new WorkQueues)->for(adminWithRole('finance')))->firstWhere('key', 'verification'))->toBeNull();
});
