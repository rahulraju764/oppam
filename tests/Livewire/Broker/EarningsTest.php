<?php

declare(strict_types=1);

use App\Enums\BureauRole;
use App\Livewire\Broker\Earnings;
use App\Models\Broker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/*
| P7.8 — /broker/earnings and its statements (PRD §11A B.10 "Visibility: Owner only", B.21 "A
| Telecaller gets 403 on … earnings … even by typing the URL", R-M13-6, R-M13-7, R-M13-24): the
| Owner sees the bureau's own commission and payouts by profile code only; every other role is
| refused; a statement is the caller's own bureau's paid line — another bureau's run is a 404.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
});

it('the Owner sees totals, the ledger by profile code and paid payouts with statements (no lazy loading)', function (): void {
    Model::preventLazyLoading();
    $owner = actingAsBureau(bureauLogin(BureauRole::Owner));
    $broker = staffOf($owner)->broker;
    $paidRow = earnedCommission($broker, 60_000);
    $run = paidRun(adminWithRole());
    $this->travel(1)->days();
    earnedCommission($broker, 25_000, now()->toImmutable());

    Livewire::test(Earnings::class)->assertOk()
        ->assertSee('₹250')                                   // earned, not yet paid
        ->assertSee('₹600')                                   // paid
        ->assertSee($paidRow->profile->code)
        ->assertSee($run->label())
        ->assertSeeHtml(route('broker.earnings.statement', ['run' => $run->code]));
});

it('empty states: no commission and no payouts yet', function (): void {
    actingAsBureau(bureauLogin(BureauRole::Owner));

    Livewire::test(Earnings::class)->assertSee('No commission yet')->assertSee('No payouts yet');
});

it('shows a draft run nowhere (amounts are not final until approved)', function (): void {
    $owner = actingAsBureau(bureauLogin(BureauRole::Owner));
    earnedCommission(staffOf($owner)->broker, 60_000);
    $draft = createRun(adminWithRole());

    Livewire::test(Earnings::class)->assertDontSee($draft->label())->assertSee('No payouts yet');
});

it('★ B.21 / R-M13-24: Manager, Data entry and Telecaller get 403 on the page and the statement, even by URL', function (BureauRole $role): void {
    $owner = bureauLogin(BureauRole::Owner);
    earnedCommission(staffOf($owner)->broker, 60_000);
    $run = paidRun(adminWithRole());
    actingAsBureau(bureauLogin($role, broker: staffOf($owner)->broker));

    $this->get(brokerUrl('/earnings'))->assertForbidden();
    $this->get(brokerUrl('/earnings/statements/'.$run->code))->assertForbidden();
})->with([BureauRole::Manager, BureauRole::DataEntry, BureauRole::Telecaller]);

it('★ R-M13-6: the Owner downloads their own statement; another bureau’s run, or a run they weren’t paid in, is 404', function (): void {
    $owner = actingAsBureau(bureauLogin(BureauRole::Owner));
    $mine = staffOf($owner)->broker;
    $theirs = Broker::factory()->create();
    earnedCommission($mine, 60_000);
    earnedCommission($theirs, 70_000);
    $run = paidRun(adminWithRole());

    $pdf = $this->get(brokerUrl('/earnings/statements/'.$run->code))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->headers->get('Cache-Control'))->toContain('no-store');

    $this->travel(1)->months();
    earnedCommission($theirs, 80_000);
    $notMine = paidRun(adminWithRole());
    $this->get(brokerUrl('/earnings/statements/'.$notMine->code))->assertNotFound();
    $this->get(brokerUrl('/earnings/statements/PAY999999'))->assertNotFound();
});

it('refreshes live on commission.earned and payout.paid on the Owner channel only', function (): void {
    $owner = actingAsBureau(bureauLogin(BureauRole::Owner));
    $brokerId = staffOf($owner)->broker_id;

    $listeners = Livewire::test(Earnings::class)->instance()->getListeners();

    expect($listeners)->toBe([
        'echo-private:broker-owner.'.$brokerId.',.commission.earned' => '$refresh',
        'echo-private:broker-owner.'.$brokerId.',.payout.paid' => '$refresh',
    ]);
});

it('a guest is sent to sign in', function (): void {
    $this->get(brokerUrl('/earnings'))->assertRedirect(route('login'));
});
