<?php

declare(strict_types=1);

use App\Enums\BrokerStatus;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Livewire\Admin\Brokers\BrokerShow;
use App\Livewire\Admin\Brokers\BrokersIndex;
use App\Livewire\Admin\Brokers\PayoutRunShow;
use App\Livewire\Admin\Brokers\PayoutsIndex;
use App\Livewire\Admin\Brokers\Tabs\EarningsTab;
use App\Livewire\Admin\Brokers\Tabs\PayoutsTab;
use App\Models\Broker;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Models\Masters\District;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.8 — A07 screens (PRD §11A B.10, B.12): directory, bureau detail (Overview / Earnings / Payouts),
| payout runs and a run. Each renders, authorizes on its own, refuses tampered #[Locked] codes,
| shows its empty state and loading markup, and drives its Actions.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
});

it('directory: renders the B.12 columns for brokers.view without lazy loading; others are refused', function (): void {
    Model::preventLazyLoading();
    $broker = Broker::factory()->create(['bureau_name' => 'Kochi Kalyanam', 'imports_paused_at' => now()]);
    earnedCommission($broker, 60_000);
    BrokerReferral::factory()->create(['broker_id' => $broker->id, 'source' => ReferralSource::Referral, 'status' => ReferralStatus::Pending]);

    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(BrokersIndex::class)->assertOk()
        ->assertSee($broker->code)->assertSee('Kochi Kalyanam')->assertSee('Imports paused')->assertSee('₹600')
        ->assertDontSeeHtml('dusk="broker-create-open"');                        // support can’t create

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(BrokersIndex::class)->assertForbidden();
});

it('directory: search and filters narrow the list; an empty result shows the empty state', function (): void {
    $kochi = Broker::factory()->create(['bureau_name' => 'Kochi Kalyanam']);
    $held = Broker::factory()->create(['bureau_name' => 'Thrissur Vivah', 'payouts_held_at' => now()]);
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(BrokersIndex::class)
        ->set('q', 'kochi')->assertSee($kochi->code)->assertDontSee($held->code)
        ->set('q', '')->set('flag', 'payouts_held')->assertSee($held->code)->assertDontSee($kochi->code)
        ->set('flag', '')->set('status', BrokerStatus::Suspended->value)->assertSee('No bureaus match')
        ->set('sort', 'drop table')->assertOk();                                 // unknown sort → default
});

it('directory: "Create broker" makes the bureau and opens it; invalid input shows field errors and saves nothing', function (): void {
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(BrokersIndex::class)->call('create')->assertHasErrors(['form.name', 'form.mobile', 'form.districtId']);
    expect(Broker::query()->count())->toBe(0);

    Livewire::test(BrokersIndex::class)
        ->set('form.name', 'Lakshmi Nair')->set('form.mobile', '9847099001')
        ->set('form.districtId', (string) District::query()->value('id'))
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.brokers.show', ['broker' => Broker::query()->sole()->code]));
});

it('bureau detail: Overview renders; an admin-created bureau shows the application link; unknown code 404', function (): void {
    $this->actingAs(adminWithRole(), 'admin');
    $broker = Broker::factory()->applied()->create(['created_by_admin_id' => auth('admin')->id()]);

    Livewire::test(BrokerShow::class, ['broker' => strtolower($broker->code)])->assertOk()
        ->assertSee($broker->code)->assertSee('waiting for the owner to apply')->assertSee('bureau='.$broker->code, false);

    Livewire::test(BrokerShow::class, ['broker' => 'BRK999999'])->assertNotFound();
});

it('bureau detail: the code is #[Locked]', function (): void {
    $this->actingAs(adminWithRole(), 'admin');
    $broker = Broker::factory()->create();

    Livewire::test(BrokerShow::class, ['broker' => $broker->code])->set('code', Broker::factory()->create()->code);
})->throws(CannotUpdateLockedPropertyException::class);

it('bureau detail: suspend through the dialog — reason required, then the bureau is suspended', function (): void {
    $this->actingAs(adminWithRole(), 'admin');
    $broker = Broker::factory()->create();

    Livewire::test(BrokerShow::class, ['broker' => $broker->code])
        ->assertSeeHtml('wire:target="suspend"')
        ->call('suspend')->assertHasErrors(['reason'])
        ->set('reason', 'Referral spike under review')->call('suspend')
        ->assertHasNoErrors()->assertDispatched('toast');

    expect($broker->refresh()->status)->toBe(BrokerStatus::Suspended);
});

it('bureau detail: terms save maps errors to the fields; finance sees the edit button but can only change the rate', function (): void {
    $broker = Broker::factory()->create();
    $this->actingAs(adminWithRole(), 'admin');
    Livewire::test(BrokerShow::class, ['broker' => $broker->code])
        ->set('commissionBps', '20000')->set('reason', 'A good reason')->call('saveTerms')->assertHasErrors(['commissionBps'])
        ->set('commissionBps', 'abc')->call('saveTerms')->assertHasErrors(['commissionBps'])
        ->set('commissionBps', '1250')->call('saveTerms')->assertHasNoErrors()->assertDispatched('toast');
    expect($broker->refresh()->commission_bps)->toBe(1250);

    $this->actingAs(adminWithRole('finance'), 'admin');
    Livewire::test(BrokerShow::class, ['broker' => $broker->code])->assertSeeHtml('dusk="broker-terms-open"')
        ->assertDontSeeHtml('dusk="broker-suspend-open"')
        ->set('seatLimit', '9')->set('reason', 'More seats')->call('saveTerms')->assertForbidden();
});

it('bureau tabs: Earnings lists the ledger with totals; Payouts lists the lines; both authorize and have empty states', function (): void {
    $broker = Broker::factory()->create();
    $this->actingAs(adminWithRole(), 'admin');

    Livewire::test(EarningsTab::class, ['code' => $broker->code])->assertSee('No commission yet');
    Livewire::test(PayoutsTab::class, ['code' => $broker->code])->assertSee('No payouts yet');

    earnedCommission($broker, 60_000);
    paidRun(adminWithRole());
    Livewire::test(EarningsTab::class, ['code' => $broker->code])->assertSee('₹600')->assertSee('PAY');
    Livewire::test(PayoutsTab::class, ['code' => $broker->code])->assertSee('UTR')->assertSee('Statement');

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(EarningsTab::class, ['code' => $broker->code])->assertForbidden();
});

it('payout runs: brokers.payout only; empty state; creating opens the preview', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');
    Livewire::test(PayoutsIndex::class)->assertForbidden();

    $this->actingAs(adminWithRole('finance'), 'admin');
    Livewire::test(PayoutsIndex::class)->assertOk()->assertSee('No payout runs yet')->assertSeeHtml('wire:target="create"');

    earnedCommission(Broker::factory()->create(), 60_000);
    Livewire::test(PayoutsIndex::class)->call('create')
        ->assertRedirect(route('admin.brokers.payouts.show', ['run' => BrokerPayoutRun::query()->sole()->code]));
});

it('payout runs: a period a paid run covers asks for the override reason', function (): void {
    $finance = adminWithRole('finance');
    $this->actingAs($finance, 'admin');
    earnedCommission(Broker::factory()->create(), 60_000);
    paidRun($finance);
    earnedCommission(Broker::factory()->create(), 60_000);

    Livewire::test(PayoutsIndex::class)
        ->call('create')->assertHasErrors(['periodEnd'])->assertSet('needsOverride', true)->assertSee('Override reason')
        ->set('overrideReason', 'Late commissions found')->call('create')->assertHasNoErrors();

    expect(BrokerPayoutRun::query()->count())->toBe(2);
});

it('a run: approve, mark paid with the UTR (dialog), and the statement link appears', function (): void {
    $finance = adminWithRole('finance');
    $this->actingAs($finance, 'admin');
    $broker = Broker::factory()->create();
    earnedCommission($broker, 60_000);
    $run = createRun($finance);

    Livewire::test(PayoutRunShow::class, ['run' => $run->code])->assertOk()
        ->assertSee($broker->code)->assertSee('₹600')
        ->call('approve')->assertDispatched('toast')
        ->call('openLine', $broker->code, 'paid')->assertSet('line', $broker->code)->assertDispatched('open-modal')
        ->set('utr', 'x')->call('markPaid')->assertHasErrors(['utr'])
        ->set('utr', 'UTR55554444')->call('markPaid')->assertHasNoErrors()
        ->assertSee('UTR55554444')->assertSee('Statement');

    expect($run->refresh()->status)->toBe(PayoutRunStatus::Paid)
        ->and(lineOf($run, $broker)->status)->toBe(PayoutItemStatus::Paid);
});

it('a run: a refusal is a toast, not an error page (second approval by the same admin)', function (): void {
    $finance = adminWithRole('finance');
    $this->actingAs($finance, 'admin');
    earnedCommission(Broker::factory()->create(), 5_000_100);
    $run = createRun($finance);

    Livewire::test(PayoutRunShow::class, ['run' => $run->code])
        ->assertSee('Needs a second approver')
        ->call('approve')->assertSee('Give second approval')
        ->call('approve')->assertDispatched('toast', type: 'error');

    expect($run->refresh()->status)->toBe(PayoutRunStatus::Draft);
});

it('a run: discard a draft (reason) → back to the list; the run code is #[Locked]; unknown run 404', function (): void {
    $finance = adminWithRole('finance');
    $this->actingAs($finance, 'admin');
    earnedCommission(Broker::factory()->create(), 60_000);
    $run = createRun($finance);

    Livewire::test(PayoutRunShow::class, ['run' => $run->code])
        ->set('reason', 'Wrong period chosen')->call('discard')->assertRedirect(route('admin.brokers.payouts'));
    expect(BrokerPayoutRun::query()->count())->toBe(0);

    Livewire::test(PayoutRunShow::class, ['run' => 'PAY999999'])->assertNotFound();
    expect(fn () => Livewire::test(PayoutRunShow::class, ['run' => createRun($finance)->code])->set('code', 'PAY1'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

it('admin statement download: brokers.payout, paid lines only, a PDF never cached', function (): void {
    $finance = adminWithRole('finance');
    $broker = Broker::factory()->create();
    $other = Broker::factory()->create();
    earnedCommission($broker, 60_000);
    $run = paidRun($finance);
    $url = adminUrl('/brokers/payouts/'.$run->code.'/statements/');

    signInAdmin($this, $finance);
    $response = $this->get($url.$broker->code)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($url.$other->code)->assertNotFound();                           // not in this run

    signInAdmin($this, adminWithRole('support'));
    $this->get($url.$broker->code)->assertForbidden();
});
