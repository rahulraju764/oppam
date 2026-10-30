<?php

declare(strict_types=1);

use App\Enums\BrokerSignal;
use App\Enums\BureauRole;
use App\Enums\ReferralStatus;
use App\Livewire\Admin\Brokers\BrokerShow;
use App\Livewire\Admin\Brokers\BrokersIndex;
use App\Livewire\Admin\Brokers\SignalsPanel;
use App\Models\Broker;
use App\Models\BrokerFraudSignal;
use App\Models\BrokerReferral;
use App\Queries\Admin\DashboardAlerts;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.8c — the signals on A07 screens (PRD §11A B.12 "surfaced on the directory and A02 alerts"): the
| bureau's panel (resolve, void all), the directory badge and filter, and the A02 alert.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->owner = bureauLogin(BureauRole::Owner);
    $this->broker = staffOf($this->owner)->broker;
});

it('signals panel: lists open signals with their counts; empty when none; resolve through the dialog (brokers.edit)', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])->assertOk()->assertDontSee('Signals to review');

    $signal = BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id, 'type' => BrokerSignal::ReferralSpike, 'details' => ['this_month' => 31, 'monthly_average' => 4]]);
    $panel = Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])
        ->assertSee('Signals to review (1)')->assertSee('Referral spike')->assertSee('31');

    $panel->call('confirm', 'signal-resolve', $signal->id)->call('resolve')->assertHasErrors('reason');
    $panel->set('reason', 'Explained by a community event')->call('resolve')->assertHasNoErrors()->assertDispatched('toast');
    expect($signal->refresh()->resolved_at)->not->toBeNull();
    $panel->assertDontSee('Signals to review');
});

it('signals panel: void all through the dialog for brokers.payout; support sees the signals but no buttons and is refused', function (): void {
    BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id, 'type' => BrokerSignal::SelfReferral, 'details' => ['profiles' => ['OPM1001']]]);
    $commission = earnedCommission($this->broker, 10_000);

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])->assertSee('OPM1001')
        ->assertDontSeeHtml('dusk="signal-void-all-open"')->assertDontSee('Resolve')
        ->set('reason', 'Support tries it')->call('voidAll')->assertForbidden();
    expect($commission->refresh()->status)->toBe(ReferralStatus::Earned);

    $this->actingAs(adminWithRole('finance'), 'admin');
    Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])
        ->call('confirm', 'signal-void-all')->set('reason', 'Self-referral confirmed')->call('voidAll')->assertDispatched('toast');
    expect($commission->refresh()->status)->toBe(ReferralStatus::Void);
});

it('signals panel: brokers.view only, code #[Locked], another bureau’s signal can’t be resolved through it', function (): void {
    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    $foreign = BrokerFraudSignal::factory()->create(['broker_id' => Broker::factory()->create()->id]);
    Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])
        ->set('target', $foreign->id)->set('reason', 'Tampered signal id')->call('resolve')->assertDispatched('toast', type: 'error');
    expect($foreign->refresh()->resolved_at)->toBeNull();

    expect(fn () => Livewire::test(SignalsPanel::class, ['code' => $this->broker->code])->set('code', $foreign->broker_id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('the bureau Overview shows the panel; the directory shows a signal badge and filters by it', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');
    $clean = Broker::factory()->create();
    BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id, 'type' => BrokerSignal::ConsentDisputes]);

    Livewire::test(BrokerShow::class, ['broker' => $this->broker->code])->assertSeeLivewire(SignalsPanel::class);
    Livewire::test(BrokersIndex::class)->assertSee('1 signal')
        ->set('flag', 'signals')->assertSee($this->broker->code)->assertDontSee($clean->code);
});

it('A02: bureaus with open signals raise an alert for brokers.view; resolved ones don’t', function (): void {
    $signal = BrokerFraudSignal::factory()->create(['broker_id' => $this->broker->id]);
    $text = fn (bool $brokers): string => collect(app(DashboardAlerts::class)->for([], false, bureaus: $brokers))->pluck('text')->implode(' ');

    expect($text(true))->toContain('fraud or quality signals')->toContain($this->broker->code)
        ->and($text(false))->not->toContain('fraud or quality signals');

    $signal->forceFill(['resolved_at' => now(), 'open_key' => null])->save();
    expect($text(true))->not->toContain('fraud or quality signals');
    expect(BrokerReferral::query()->count())->toBe(0);
});
