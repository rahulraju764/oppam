<?php

declare(strict_types=1);

use App\Domain\Broker\PanNumber;
use App\Enums\BureauRole;
use App\Enums\KycSubmissionStatus;
use App\Livewire\Admin\Brokers\KycQueue;
use App\Livewire\Broker\Dashboard;
use App\Livewire\Broker\Kyc;
use App\Models\AuditLog;
use App\Models\BrokerKyc;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.1 — portal screens: dashboard (B.4 widgets by role, zero data), the permission-filtered sidebar
| (B.11), /broker/kyc (Owner), and the A07 KYC queue (brokers.kyc, decisions, PAN viewer on demand).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    fakeVerificationDisk();
    Notification::fake();
});

it('B.4: each role sees its widgets, all with zero data', function (BureauRole $role, array $see, array $dontSee): void {
    actingAsBureau(bureauLogin($role));

    $page = $this->get(brokerUrl(''))->assertOk();
    foreach ($see as $text) {
        $page->assertSee($text);
    }
    foreach ($dontSee as $text) {
        $page->assertDontSee($text);
    }
})->with([
    'owner' => [BureauRole::Owner, ['Referrals', 'Managed profiles', 'Imports', 'Client inbox', 'Commission', 'Seats used', 'Follow-ups due today'], []],
    'manager' => [BureauRole::Manager, ['Referrals', 'Imports', 'Client inbox'], ['Commission', 'Seats used']],
    'data entry' => [BureauRole::DataEntry, ['Managed profiles', 'Imports'], ['Referrals', 'Client inbox', 'Commission']],
    'telecaller' => [BureauRole::Telecaller, ['Managed profiles', 'Client inbox'], ['Referrals', 'Imports', 'Commission', 'Seats used']],
]);

it('B.11: the sidebar shows only permitted items; before KYC the data items are shown locked', function (): void {
    actingAsBureau(bureauLogin(BureauRole::Telecaller));
    $this->get(brokerUrl(''))->assertOk()
        ->assertDontSee('Add a profile')->assertDontSee('Bulk import')->assertDontSee('KYC &amp; bank', false);

    actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $this->get(brokerUrl(''))->assertSeeHtml('href="'.brokerUrl('/profiles/create').'"');

    actingAsBureau(applicantOwner());
    $this->get(brokerUrl(''))->assertOk()
        ->assertSee('Add a profile')
        ->assertSee('after KYC')
        ->assertDontSeeHtml('href="'.brokerUrl('/profiles/create').'"')
        ->assertSeeHtml('href="'.brokerUrl('/kyc').'"');
});

it('B.11 phones: the header drawer carries the whole permitted menu, locked items with the reason, and sign-out', function (): void {
    actingAsBureau(applicantOwner());
    $html = (string) $this->get(brokerUrl(''))->assertOk()->getContent();
    $drawer = Str::between($html, '<ul class="navbar-nav ms-auto menus">', '</ul>');

    // The sidebar is hidden below 768 px: the unlocked links, the locked items with their reason
    // and sign-out all live in the drawer list too.
    expect($drawer)->toContain('href="'.brokerUrl('/kyc').'"')
        ->and($drawer)->toContain('broker-nav-locked')
        ->and($drawer)->toContain('Add a profile')
        ->and($drawer)->toContain('after KYC')
        ->and($drawer)->toContain('broker-nav-logout')
        ->and($drawer)->not->toContain('href="'.brokerUrl('/profiles/create').'"');
});

it('/broker/kyc: the Owner sends KYC; secrets never come back; staff get 403', function (): void {
    $owner = applicantOwner();
    $owner->brokerStaff->broker->forceFill(['pan_hash' => PanNumber::hash(TEST_PAN), 'pan_last4' => '234F'])->save();
    actingAsBureau($owner->refresh());

    Livewire::test(Kyc::class)
        ->assertSee('Send KYC')
        ->assertSeeHtml('wire:loading wire:target="submit"')
        ->set('pan', 'abcde1234f')
        ->set('panImage', idPhoto('pan.jpg'))
        ->set('accountNumber', '123456789012')
        ->set('ifsc', 'sbin0001234')
        ->set('accountName', 'Rajan Pillai')
        ->call('submit')
        ->assertHasNoErrors()
        ->assertSet('pan', '')->assertSet('accountNumber', '')->assertSet('panImage', null)
        ->assertSee('your KYC has been sent')
        ->assertSee('XXXXXX234F')
        ->assertDontSee('Send KYC');

    actingAsBureau(bureauLogin(BureauRole::Manager, 'kyc_pending'));
    $this->get(brokerUrl('/kyc'))->assertForbidden();
});

it('/broker/kyc shows field errors and stores nothing', function (): void {
    actingAsBureau(applicantOwner());

    Livewire::test(Kyc::class)->set('pan', 'X')->call('submit')->assertHasErrors(['pan', 'panImage', 'accountNumber', 'ifsc', 'accountName']);
    expect(BrokerKyc::query()->count())->toBe(0);
});

it('A07 KYC queue: lists, opens a bureau, views the PAN on demand (audited), approves', function (): void {
    $owner = applicantOwner();
    $broker = $owner->brokerStaff->broker;
    $photo = idPhoto();
    BrokerKyc::factory()->create(['broker_id' => $broker->id, 'pan_image_path' => app(App\Contracts\DocumentVault::class)->put((string) file_get_contents((string) $photo->getRealPath())), 'pan_image_mime' => 'image/jpeg']);
    $broker->forceFill(['status' => App\Enums\BrokerStatus::KycPending, 'kyc_status' => App\Enums\BrokerKycStatus::Pending])->save();
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(KycQueue::class)
        ->assertSee($broker->code)
        ->call('select', $broker->code)
        ->assertSee('XXXX9012')
        ->assertDontSeeHtml('/pan?')
        ->call('viewPan')
        ->assertSeeHtml('/brokers/kyc/'.$broker->code.'/pan')
        ->call('approve')
        ->assertDispatched('toast')
        ->assertSet('selected', '');

    expect(BrokerKyc::query()->sole()->status)->toBe(KycSubmissionStatus::Verified)
        ->and(AuditLog::query()->where('action', 'brokers.kyc_pan_opened')->count())->toBe(1);
});

it('A07 KYC queue: reject needs a reason; brokers.kyc required; locked viewer props', function (): void {
    $owner = applicantOwner();
    $broker = $owner->brokerStaff->broker;
    BrokerKyc::factory()->create(['broker_id' => $broker->id]);
    $broker->forceFill(['status' => App\Enums\BrokerStatus::KycPending, 'kyc_status' => App\Enums\BrokerKycStatus::Pending])->save();

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(KycQueue::class)->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(KycQueue::class)
        ->call('select', $broker->code)
        ->call('rejectKyc')->assertHasErrors('reason')
        ->set('reason', 'PAN photo is blurred.')->call('rejectKyc')->assertHasNoErrors();
    expect(BrokerKyc::query()->sole()->status)->toBe(KycSubmissionStatus::Rejected);

    expect(fn () => Livewire::test(KycQueue::class)->set('panUrl', 'https://evil.test'))->toThrow(CannotUpdateLockedPropertyException::class);
});
