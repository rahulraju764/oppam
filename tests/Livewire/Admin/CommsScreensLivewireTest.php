<?php

declare(strict_types=1);

use App\Enums\CommsChannel;
use App\Livewire\Admin\Comms\Announcements;
use App\Livewire\Admin\Comms\DeliveryLog;
use App\Livewire\Admin\Comms\Templates;
use App\Models\Announcement;
use App\Models\CommunicationTemplate;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| A14 admin screens. P8.3a: templates, announcements, delivery log — render, permission, validation,
| states. The campaign / journey screen checks are the first pass's, kept for P8.3b.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->admin = adminWithRole('super_admin');
    $this->actingAs($this->admin, 'admin');
});

it('P8.3a templates: lists notification copy per event × channel; editing one saves a version with a live preview', function (): void {
    $screen = Livewire::test(Templates::class)
        ->assertOk()->assertSee('Message templates')->assertSee('Standard')
        ->call('editEvent', 'profile_approved', 'email')
        ->assertDispatched('open-drawer', name: 'template-editor')
        ->set('subject', 'Hello {first_name}')
        ->set('body', '**Live!** Open {url}')
        ->assertSeeHtml('<strong>Live!</strong>')           // preview, sample values
        ->assertSee('Hello Anjali')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success');

    expect(CommunicationTemplate::query()->where('key', 'profile_approved.email')->sole()->current_version)->toBe(1);
    $screen->assertSee('v1');
});

it('P8.3a templates: validation errors show on the fields; a channel the event never uses is a 404', function (): void {
    Livewire::test(Templates::class)
        ->call('editEvent', 'profile_approved', 'email')
        ->set('subject', '')->set('body', 'Hi {phone}')
        ->call('save')
        ->assertHasErrors(['subject', 'body']);

    Livewire::test(Templates::class)->call('editEvent', 'profile_liked', 'sms')->assertNotFound();
    Livewire::test(Templates::class)->call('editEvent', 'made_up', 'email')->assertNotFound();
    expect(CommunicationTemplate::query()->count())->toBe(0);
});

it('P8.3a templates: compare and bring back an earlier version', function (): void {
    $screen = Livewire::test(Templates::class)->call('editEvent', 'profile_approved', 'in_app')
        ->set('body', "Title one\nFirst text")->call('save')
        ->set('body', "Title two\nSecond text")->call('save');

    $screen->call('compare', 1)->assertSeeHtml('legal-diff__line is-removed')
        ->call('revert', 1)->assertDispatched('toast', type: 'success');

    expect(CommunicationTemplate::query()->sole()->currentBody())->toBe("Title one\nFirst text");
});

it('P8.3a templates: campaign texts tab — create one with its own key', function (): void {
    Livewire::test(Templates::class)->set('tab', 'campaigns')
        ->assertSee('No campaign texts yet')
        ->call('newCampaign')
        ->set('campaignName', 'Onam offer')->set('campaignKey', 'onam_2026')->set('campaignChannel', 'email')
        ->set('subject', 'Onam offer')->set('body', 'Hello {first_name}')
        ->call('save')->assertHasNoErrors();

    expect(CommunicationTemplate::query()->where('key', 'campaign.onam_2026')->exists())->toBeTrue();
});

it('P8.3a tampered #[Locked] props are rejected: the template being edited, the version compared, the announcement edited', function (string $component, string $property, mixed $value): void {
    expect(fn () => Livewire::test($component)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'template key' => [Templates::class, 'editing', 'profile_approved.email'],
    'compared version' => [Templates::class, 'comparing', 1],
    'announcement code' => [Announcements::class, 'editing', 'ANNAAAAAAAA'],
]);

it('P8.3a templates screen needs templates.edit', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');

    Livewire::test(Templates::class)->assertForbidden();
});

it('P8.3a announcements: create → live; end now; empty state; a read-only admin sees no buttons', function (): void {
    Livewire::test(Announcements::class)->assertSee('No announcements yet')
        ->call('create')
        ->set('title', 'Festive offer')->set('body', '20 % off Gold this weekend.')
        ->set('actionText', 'See plans')->set('actionUrl', '/plans')
        ->call('save')->assertHasNoErrors()
        ->assertDispatched('toast', type: 'success')
        ->assertSee('Festive offer')->assertSee('Live');

    $code = Announcement::query()->sole()->code;
    Livewire::test(Announcements::class)->call('end', $code)->assertDispatched('toast', type: 'success')->assertSee('Ended');

    $this->actingAs(adminWithRole('content_editor'), 'admin');   // campaigns.view only
    Livewire::test(Announcements::class)->assertOk()->assertDontSeeHtml('dusk="announcement-new"')->assertDontSee('End now')
        ->call('create')->assertForbidden();
});

it('P8.3a announcements: an unsafe link shows a field error and saves nothing', function (): void {
    Livewire::test(Announcements::class)->call('create')
        ->set('title', 'x')->set('body', 'y')->set('actionText', 'Go')->set('actionUrl', 'javascript:alert(1)')
        ->call('save')->assertHasErrors(['actionUrl']);

    expect(Announcement::query()->count())->toBe(0);
});

it('P8.3a delivery log: filters by channel / status, empty state, masked recipients; resend button only for resendable rows', function (): void {
    OutboundMessage::factory()->create(['channel' => CommsChannel::Email, 'recipient_masked' => 'a•••@example.com']);
    OutboundMessage::factory()->create(['channel' => CommsChannel::Sms, 'recipient_masked' => '+91 98•••••001', 'status' => 'FAILED']);

    Livewire::test(DeliveryLog::class)
        ->assertSee('a•••@example.com')->assertSee('+91 98•••••001')
        ->assertDontSee('Send again')   // the failed row holds no notification
        ->set('channel', 'sms')->assertDontSee('a•••@example.com')
        ->set('channel', '')->set('status', 'DELIVERED')->assertSee('No messages match these filters.');
});
