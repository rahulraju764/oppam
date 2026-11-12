<?php

declare(strict_types=1);

use App\Enums\CampaignStatus;
use App\Enums\SettingKey;
use App\Jobs\Comms\SendCampaignBatch;
use App\Livewire\Admin\Comms\CampaignBuilder;
use App\Livewire\Admin\Comms\Campaigns;
use App\Livewire\Admin\Comms\CampaignShow;
use App\Models\Campaign;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P8.3b1 — A14 campaign screens: list (filters, empty state, numbers), builder (live audience,
| validation, draft only), campaign page (send, test, approve by another admin, cancel, poll while
| sending). Permission on every screen; tampered #[Locked] codes rejected.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Queue::fake([SendCampaignBatch::class]);
    $this->admin = adminWithRole('super_admin');
    $this->actingAs($this->admin, 'admin');
});

it('A14 list: empty state, then rows with status and numbers; filters by status / channel (unknown values ignored)', function (): void {
    Livewire::test(Campaigns::class)->assertOk()->assertSee('No campaigns yet')->assertSeeHtml('dusk="campaign-new"');

    campaignMember();
    $sent = app(App\Actions\Admin\Comms\SubmitCampaign::class)->handle($this->admin, draftCampaign($this->admin, ['name' => 'Sent one']));
    runCampaignBatch($sent);
    draftCampaign($this->admin, ['name' => 'Still a draft']);

    Livewire::test(Campaigns::class)
        ->assertSee('Sent one')->assertSee('Still a draft')->assertSee('Completed')
        ->set('status', 'DRAFT')->assertDontSee('Sent one')->assertSee('Still a draft')
        ->set('status', "x' OR 1=1")->assertSee('Sent one')
        ->set('status', '')->set('channel', 'sms')->assertSee('No campaigns match these filters');
});

it('A14 screens: campaigns.view can list and open campaigns but not build them; no permission → 403', function (): void {
    $campaign = draftCampaign($this->admin);

    $this->actingAs(adminWithRole('content_editor'), 'admin');   // campaigns.view
    Livewire::test(Campaigns::class)->assertOk()->assertDontSeeHtml('dusk="campaign-new"');
    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])->assertOk()->assertDontSeeHtml('dusk="campaign-submit"')
        ->call('submit')->assertForbidden();
    Livewire::test(CampaignBuilder::class)->assertForbidden();

    $this->actingAs(adminWithRole('support'), 'admin');          // nothing in Communication
    Livewire::test(Campaigns::class)->assertForbidden();
    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])->assertForbidden();
});

it('A14 builder: the audience estimate follows the filters and the channel; save validates, then opens the campaign page', function (): void {
    campaignMember();
    campaignMember(female: false);

    $screen = Livewire::test(CampaignBuilder::class)->assertOk()->assertSee('Audience now')
        ->assertSeeHtml('wire:loading')
        ->assertSet('channel', 'email');
    expect($screen->instance()->audience())->toBe(2);

    $screen->set('gender', 'FEMALE');
    expect($screen->instance()->audience())->toBe(1);
    $screen->set('channel', 'sms');
    expect($screen->instance()->audience())->toBe(0);   // SMS offers are opt-in

    $screen->set('channel', 'email')->set('name', '')->call('save')->assertHasErrors(['name', 'subject', 'message']);
    expect(Campaign::query()->count())->toBe(0);

    $screen->set('name', 'Brides offer')->set('subject', 'Hello {first_name}')->set('message', 'Gold is 20 % off.')
        ->set('actionText', 'See plans')->set('actionUrl', '/plans')
        ->call('save')->assertHasNoErrors();

    $campaign = Campaign::query()->sole();
    $screen->assertRedirect(route('admin.comms.campaigns.show', $campaign->code));
    expect($campaign->segment_filters)->toBe(['gender' => 'FEMALE'])->and($campaign->status)->toBe(CampaignStatus::Draft);
});

it('A14 builder: an unknown religion id or a bad date is a field error, never a query', function (): void {
    Livewire::test(CampaignBuilder::class)->set('religionId', 999999)->set('registeredFrom', '2026-13-40')
        ->set('name', 'x')->set('subject', 'y')->set('message', 'z')
        ->call('save')->assertHasErrors(['religionId', 'registeredFrom']);
});

it('A14 builder: only a draft opens for editing; a sent campaign is 404', function (): void {
    campaignMember();
    $draft = draftCampaign($this->admin, ['name' => 'Editable']);
    Livewire::test(CampaignBuilder::class, ['campaign' => $draft->code])->assertSet('name', 'Editable')->assertSet('editing', $draft->code);

    app(App\Actions\Admin\Comms\SubmitCampaign::class)->handle($this->admin, $draft);
    Livewire::test(CampaignBuilder::class, ['campaign' => $draft->code])->assertNotFound();
});

it('A14 tampered #[Locked] campaign codes are rejected (builder and campaign page)', function (string $component, string $property): void {
    $campaign = draftCampaign($this->admin);
    $other = draftCampaign($this->admin);

    expect(fn () => Livewire::test($component, ['campaign' => $campaign->code])->set($property, $other->code))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'builder' => [CampaignBuilder::class, 'editing'],
    'campaign page' => [CampaignShow::class, 'code'],
]);

it('A14 campaign page: send freezes the audience and starts; numbers poll while it sends; a second send is refused with a toast', function (): void {
    campaignMember();
    $campaign = draftCampaign($this->admin);

    $page = Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])
        ->assertSeeHtml('dusk="campaign-submit"')->assertSeeHtml('dusk="campaign-test"')
        ->call('submit')->assertDispatched('toast', type: 'success')
        ->assertSee('Sending')->assertSeeHtml('wire:poll.10s');

    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending)->and($campaign->recipient_count)->toBe(1);
    $page->call('submit')->assertDispatched('toast', type: 'danger');
});

it('A14 campaign page: above the threshold it waits; the submitter sees no Approve button and is refused; another admin approves', function (): void {
    settingIs(SettingKey::CampaignsApprovalThreshold, 0);
    campaignMember();
    $campaign = draftCampaign($this->admin);

    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])->call('submit')
        ->assertSee('Waiting for approval')->assertDontSeeHtml('dusk="campaign-approve"')
        ->call('approve')->assertDispatched('toast', type: 'danger');
    expect($campaign->refresh()->status)->toBe(CampaignStatus::PendingApproval);

    $this->actingAs(adminWithRole(), 'admin');
    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])
        ->assertSeeHtml('dusk="campaign-approve"')->call('approve')->assertDispatched('toast', type: 'success');
    expect($campaign->refresh()->status)->toBe(CampaignStatus::Sending);
});

it('A14 campaign page: cancel; finished campaigns offer nothing but a note; an unknown code is 404', function (): void {
    $campaign = draftCampaign($this->admin);

    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])->call('cancel')->assertDispatched('toast', type: 'success')
        ->assertSee('Finished campaigns can’t be sent again', false)->assertDontSeeHtml('dusk="campaign-cancel"');

    Livewire::test(CampaignShow::class, ['campaign' => 'CMPNOPENOPE1'])->assertNotFound();
});

it('A14 campaign page: an in-app campaign has no test button; an email test goes to the admin', function (): void {
    Illuminate\Support\Facades\Notification::fake();
    $inApp = draftCampaign($this->admin, ['channel' => App\Enums\CommsChannel::InApp]);
    Livewire::test(CampaignShow::class, ['campaign' => $inApp->code])->assertDontSeeHtml('dusk="campaign-test"');

    $email = draftCampaign($this->admin);
    Livewire::test(CampaignShow::class, ['campaign' => $email->code])->call('sendTest')->assertDispatched('toast', type: 'success');
    Illuminate\Support\Facades\Notification::assertSentOnDemand(App\Notifications\Admin\CampaignTestMessage::class);
});

it('A14 campaign page: stored text is escaped (no HTML from a campaign reaches the admin page)', function (): void {
    $campaign = draftCampaign($this->admin, ['channel' => App\Enums\CommsChannel::InApp, 'subject' => '<b>Bold title</b>', 'message' => '<img src=x onerror=alert(1)>']);

    Livewire::test(CampaignShow::class, ['campaign' => $campaign->code])
        ->assertDontSeeHtml('<b>Bold title</b>')->assertDontSeeHtml('<img src=x')
        ->assertSee('<b>Bold title</b>');
});
