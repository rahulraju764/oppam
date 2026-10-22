<?php

declare(strict_types=1);

use App\Enums\VerificationRejectReason;
use App\Enums\VerificationStatus;
use App\Livewire\Member\Profile\MyProfile;
use App\Livewire\Member\Verification\Upload;
use App\Models\VerificationRequest;
use App\Services\Admin\Impersonation;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P6.1 — /verify (M09, template verification.php): steps, the upload form and its states
| (waiting, verified, rejected with guidance, revoked), live refresh on `.verification.changed`.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    fakeVerificationDisk();
});

it('/verify needs a signed-in member', function (): void {
    $this->get(memberUrl('/verify'))->assertRedirect(memberUrl('/login'));
    $this->actingAs(groom(), 'web')->get(memberUrl('/verify'))->assertOk()->assertSee('Verify your profile');
});

it('M09: shows the four steps and the form; sending a document switches to "being checked"', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    Livewire::test(Upload::class)
        ->assertSee('1 of 4 steps done.')
        ->assertSee('Upload your ID')
        ->assertSet('nameOnDocument', $member->profile->fullName())
        ->assertSeeHtml('wire:loading wire:target="submit"')
        ->set('docType', 'AADHAAR')
        ->assertSee('12 digits. We keep only the last 4.')
        ->set('number', TEST_AADHAAR)
        ->set('front', idPhoto())
        ->set('consent', true)
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSee('your document has been sent')
        ->assertSee('Your document is being checked')
        ->assertDontSee('Upload your ID')
        ->assertSet('front', null);

    expect(VerificationRequest::query()->where('profile_id', $member->profile->id)->sole()->status)->toBe(VerificationStatus::Pending);
});

it('shows validation errors under the fields and stores nothing', function (): void {
    $this->actingAs(groom(), 'web');

    Livewire::test(Upload::class)
        ->set('docType', 'AADHAAR')
        ->set('number', '1234')
        ->call('submit')
        ->assertHasErrors(['front', 'consent'])
        ->assertSee('Please confirm the document is yours');

    expect(VerificationRequest::query()->count())->toBe(0);
});

it('after a rejection the member reads the guidance and may send again; once verified the form is gone', function (): void {
    $member = groom();
    $request = submitId($member);
    $request->forceFill(['status' => VerificationStatus::Rejected, 'reject_reason' => VerificationRejectReason::CutOff, 'member_note' => 'The date of birth is cut off.', 'decided_at' => now()])->save();
    $this->actingAs($member, 'web');

    Livewire::test(Upload::class)
        ->assertSee('Please try again')
        ->assertSee('with all four corners')
        ->assertSee('The date of birth is cut off.')
        ->assertSee('Upload your ID');

    $member->profile->forceFill(['is_verified' => true])->save();
    Livewire::test(Upload::class)
        ->assertSee('You’re verified')
        ->assertSee('3 of 4 steps done.')
        ->assertDontSee('Upload your ID');
});

it('F05: /verify and /me refresh live on .verification.changed (own private channel only)', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');

    $key = 'echo-private:App.Models.User.'.$member->id.',.verification.changed';
    expect(Livewire::test(Upload::class)->instance()->getListeners())->toHaveKey($key)
        ->and(Livewire::test(MyProfile::class)->instance()->getListeners())->toHaveKey($key);

    // /me shows the way to the badge, then the badge.
    Livewire::test(MyProfile::class)->assertSee('Get verified');
    $member->profile->forceFill(['is_verified' => true])->save();
    Livewire::test(MyProfile::class)->assertDontSee('Get verified')->assertSee('Verified');
});

it('an admin support session sees the page but can’t send a document', function (): void {
    $member = groom();
    $this->actingAs($member, 'web');
    $support = App\Models\ImpersonationSession::factory()->create(['admin_user_id' => adminWithRole()->id, 'user_id' => $member->id]);
    session()->put(Impersonation::SESSION_KEY, $support->id);

    Livewire::test(Upload::class)
        ->assertSee('can’t be sent while our team is viewing this account')
        ->set('docType', 'AADHAAR')->set('number', TEST_AADHAAR)->set('front', idPhoto())->set('consent', true)
        ->call('submit')
        ->assertDispatched('toast');

    expect(VerificationRequest::query()->count())->toBe(0);
});
