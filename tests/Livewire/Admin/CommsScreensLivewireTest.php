<?php

declare(strict_types=1);

use App\Livewire\Admin\Comms\Announcements;
use App\Livewire\Admin\Comms\CampaignBuilder;
use App\Livewire\Admin\Comms\Campaigns;
use App\Livewire\Admin\Comms\DeliveryLog;
use App\Livewire\Admin\Comms\Journeys;
use App\Livewire\Admin\Comms\Templates;
use App\Models\AutomatedJourney;
use App\Models\CommunicationTemplate;
use Livewire\Livewire;

beforeEach(function (): void {
    seedAdminRoles();
    $this->admin = adminWithRole('super_admin');
    $this->actingAs($this->admin, 'admin');
});

it('P8.3: comms templates screen mounts and creates a new template', function (): void {
    Livewire::withoutLazyLoading()
        ->test(Templates::class)
        ->assertOk()
        ->assertSee('Message Templates')
        ->call('openCreateModal')
        ->set('key', 'welcome_email')
        ->set('channel', 'email')
        ->set('name', 'Welcome Email')
        ->set('subject', 'Welcome to Oppam Matrimony')
        ->set('body', 'Hello {{name}}, welcome to our platform.')
        ->call('save')
        ->assertHasNoErrors();

    expect(CommunicationTemplate::query()->where('key', 'welcome_email')->exists())->toBeTrue();
});

it('P8.3: comms announcements screen mounts and broadcasts', function (): void {
    Livewire::withoutLazyLoading()
        ->test(Announcements::class)
        ->assertOk()
        ->assertSee('Announcements &amp; Banners', false)
        ->call('openCreateModal')
        ->set('title', 'Festive Offer')
        ->set('body', 'Enjoy 20% discount on Premium plans this weekend!')
        ->set('broadcastNow', true)
        ->call('save')
        ->assertHasNoErrors();
});

it('P8.3: campaigns and campaign builder screens mount properly', function (): void {
    Livewire::withoutLazyLoading()
        ->test(Campaigns::class)
        ->assertOk()
        ->assertSee('Campaigns');

    Livewire::withoutLazyLoading()
        ->test(CampaignBuilder::class)
        ->assertOk()
        ->assertSee('Campaign Builder')
        ->set('name', 'Test Segment Campaign')
        ->set('channel', 'email')
        ->set('subject', 'Hello grooms')
        ->set('message', 'Special groom offers')
        ->assertSee('Estimated Audience');
});

it('P8.3: automated journeys and delivery log screens mount properly', function (): void {
    $component = Livewire::withoutLazyLoading()
        ->test(Journeys::class)
        ->assertOk()
        ->assertSee('Automated Lifecycle Journeys')
        ->assertSee('Incomplete Registration Wizard');

    $journey = AutomatedJourney::query()->where('key', 'wizard_incomplete')->first();
    expect($journey)->not->toBeNull();

    $component->call('toggleActive', $journey->id);
    expect($journey->fresh()->is_active)->toBeFalse();

    Livewire::withoutLazyLoading()
        ->test(DeliveryLog::class)
        ->assertOk()
        ->assertSee('Outbound Delivery Log');
});
