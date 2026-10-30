<?php

declare(strict_types=1);

use App\Actions\Admin\Brokers\Payouts\ApprovePayoutRun;
use App\Actions\Admin\Brokers\Payouts\CreatePayoutRun;
use App\Actions\Admin\Brokers\Payouts\DiscardPayoutRun;
use App\Actions\Admin\Brokers\Payouts\MarkPayoutPaid;
use App\Actions\Admin\Brokers\Payouts\RollPayoutLineForward;
use App\Actions\Admin\Brokers\SetBrokerPayoutHold;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\PayoutItemStatus;
use App\Enums\PayoutRunStatus;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Events\Broker\PayoutPaid;
use App\Exceptions\Admin\PayoutRefused;
use App\Models\AuditLog;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\BrokerReferral;
use App\Notifications\Broker\PayoutPaid as PayoutPaidNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| ★ PayoutRunTest (P7.8 Done-when; PRD §11A B.10, B.17, B.19, B.21, R-A07-2, R-A07-4, R-A07-5;
| decisions 2026-10-29): a DRAFT run is the preview; KYC-less / suspended / held bureaus are left
| out; balances under ₹500 roll forward and are taken over by the next run; a line above ₹50,000
| needs a second, different approver; the run row is locked (a second approval sees "already
| approved"); mark paid with a unique UTR; commissions are paid once; a refund after payment is
| clawed back once; one open run at a time; a paid period needs an audited override.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Notification::fake();
    $this->finance = adminWithRole('finance');
});

it('creates a DRAFT run with one line per payable bureau and claims its EARNED commissions (the preview)', function (): void {
    $a = Broker::factory()->create();
    $b = Broker::factory()->create();
    $rowsA = [earnedCommission($a, 40_000), earnedCommission($a, 30_000)];
    earnedCommission($b, 60_000);

    $run = createRun($this->finance);

    expect($run->status)->toBe(PayoutRunStatus::Draft)
        ->and($run->code)->toStartWith('PAY')
        ->and($run->items()->count())->toBe(2);
    $line = lineOf($run, $a);
    expect($line->gross_paise)->toBe(70_000)->and($line->net_paise)->toBe(70_000)->and($line->status)->toBe(PayoutItemStatus::Pending);
    foreach ($rowsA as $row) {
        expect($row->refresh()->payout_item_id)->toBe($line->id)->and($row->status)->toBe(ReferralStatus::Earned);
    }
    expect(AuditLog::query()->where('action', 'brokers.payout_run_created')->sole()->after['lines'])->toBe(2);
});

it('R-A07-2 / B.21: KYC-less, suspended and payout-held bureaus are left out; their commissions wait', function (array $state): void {
    $paid = Broker::factory()->create();
    earnedCommission($paid, 60_000);
    $excluded = Broker::factory()->create($state);
    $waiting = earnedCommission($excluded, 90_000);

    $run = createRun($this->finance);

    expect($run->items()->where('broker_id', $excluded->id)->exists())->toBeFalse()
        ->and($waiting->refresh()->payout_item_id)->toBeNull();
})->with([
    'KYC pending' => [['status' => BrokerStatus::KycPending, 'kyc_status' => BrokerKycStatus::Pending]],
    'KYC revoked' => [['kyc_status' => BrokerKycStatus::Revoked]],
    'suspended' => [['status' => BrokerStatus::Suspended]],
    'deactivated' => [['status' => BrokerStatus::Deactivated]],
    'payouts held' => [['payouts_held_at' => now(), 'payouts_hold_reason' => 'Referral spike under review']],
]);

it('only commissions earned by the end of the period (IST) are in the run', function (): void {
    $broker = Broker::factory()->create();
    [, $end] = lastMonth();
    $inside = earnedCommission($broker, 60_000, $end->setTime(23, 59)->utc());          // 23:59 IST on the last day
    $after = earnedCommission($broker, 70_000, $end->addDay()->setTime(0, 1)->utc());    // 00:01 IST the next day

    $run = createRun($this->finance);

    expect($inside->refresh()->payout_item_id)->not->toBeNull()
        ->and($after->refresh()->payout_item_id)->toBeNull()
        ->and(lineOf($run, $broker)->gross_paise)->toBe(60_000);
});

it('R-A07-5: under ₹500 rolls forward; the next run takes the balance over and pays every commission behind it', function (): void {
    $broker = Broker::factory()->create();
    $small = earnedCommission($broker, 30_000);

    $first = paidRun($this->finance);
    $carried = lineOf($first, $broker);
    expect($carried->status)->toBe(PayoutItemStatus::CarriedForward)
        ->and($carried->carried_forward_paise)->toBe(30_000)
        ->and($first->status)->toBe(PayoutRunStatus::Paid)      // nothing to pay → complete at approval
        ->and($small->refresh()->status)->toBe(ReferralStatus::Earned);

    $this->travel(1)->months();
    $more = earnedCommission($broker, 25_000);
    $second = paidRun($this->finance);
    $line = lineOf($second, $broker);

    expect($line->carried_in_paise)->toBe(30_000)
        ->and($line->gross_paise)->toBe(25_000)
        ->and($line->net_paise)->toBe(55_000)
        ->and($line->status)->toBe(PayoutItemStatus::Paid)
        ->and($carried->refresh()->rolled_into_item_id)->toBe($line->id)
        ->and($small->refresh()->status)->toBe(ReferralStatus::Paid)
        ->and($small->payout_item_id)->toBe($line->id)            // moved onto the line that paid it
        ->and($more->refresh()->status)->toBe(ReferralStatus::Paid);
});

it('R-A07-4: a line above ₹50,000 needs a second approver who is a different admin', function (): void {
    $broker = Broker::factory()->create();
    earnedCommission($broker, 5_000_100);
    $run = createRun($this->finance);
    $approve = app(ApprovePayoutRun::class);

    $after = $approve->handle($this->finance, $run);
    expect($after->status)->toBe(PayoutRunStatus::Draft)->and($after->approved_by_admin_id)->toBe($this->finance->id);

    expect(fn () => $approve->handle($this->finance, $run->refresh()))->toThrow(PayoutRefused::class, 'different admin');

    $second = adminWithRole('finance');
    $done = $approve->handle($second, $run->refresh());
    expect($done->status)->toBe(PayoutRunStatus::Approved)
        ->and($done->second_approved_by_admin_id)->toBe($second->id)
        ->and(AuditLog::query()->where('action', 'brokers.payout_run_approved')->count())->toBe(2);
});

it('R-A07-4: a run waiting for its second approval completes on the next approval once no line needs one any more', function (): void {
    earnedCommission(Broker::factory()->create(), 5_000_100);
    $run = createRun($this->finance);
    app(ApprovePayoutRun::class)->handle($this->finance, $run);

    settingIs(App\Enums\SettingKey::BrokerPayoutSecondApproverPaise, 10_000_000);   // threshold raised to ₹1 lakh

    $done = app(ApprovePayoutRun::class)->handle($this->finance, $run->refresh());
    expect($done->status)->toBe(PayoutRunStatus::Approved)->and($done->second_approved_by_admin_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'brokers.payout_run_approved')->latest('created_at')->orderByDesc('id')->first()?->after['step'])->toBe('final');
});

it('R-A07-4: at or under ₹50,000 per bureau one approval is enough (even when the run total is larger)', function (): void {
    foreach (range(1, 3) as $i) {
        earnedCommission(Broker::factory()->create(), 4_000_000);   // ₹40,000 each, ₹1.2 lakh in total
    }

    $run = app(ApprovePayoutRun::class)->handle($this->finance, createRun($this->finance));

    expect($run->status)->toBe(PayoutRunStatus::Approved)->and($run->second_approved_by_admin_id)->toBeNull();
});

it('B.19: two admins approving the same run — the run row is locked and the second is told it is already approved', function (): void {
    earnedCommission(Broker::factory()->create(), 60_000);
    $run = createRun($this->finance);
    $stale = $run->replicate()->forceFill(['id' => $run->id]);   // the second admin's copy, loaded before the first approved
    $stale->exists = true;

    app(ApprovePayoutRun::class)->handle($this->finance, $run);

    expect(fn () => app(ApprovePayoutRun::class)->handle(adminWithRole('finance'), $stale))->toThrow(PayoutRefused::class, 'already been approved');
});

it('mark paid: UTR recorded, commissions PAID, run PAID, the Owner told live (code + amount only) and notified', function (): void {
    Event::fake([PayoutPaid::class]);
    $ownerLogin = bureauLogin(BureauRole::Owner);
    $broker = staffOf($ownerLogin)->broker;
    $row = earnedCommission($broker, 119_880);
    $run = createRun($this->finance);
    app(ApprovePayoutRun::class)->handle($this->finance, $run);

    $item = app(MarkPayoutPaid::class)->handle($this->finance, $run->refresh(), $broker->code, ' utr12345678 ');

    expect($item->status)->toBe(PayoutItemStatus::Paid)
        ->and($item->utr)->toBe('UTR12345678')
        ->and($row->refresh()->status)->toBe(ReferralStatus::Paid)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::Paid);
    Event::assertDispatched(PayoutPaid::class, function (PayoutPaid $event) use ($broker, $run): bool {
        $payload = $event->broadcastWith();

        return $event->broadcastOn()[0]->name === 'private-broker-owner.'.$broker->id
            && $event->broadcastAs() === 'payout.paid'
            && $payload === ['run' => $run->code, 'amount' => '₹1,198.80'];
    });
    Notification::assertSentTo($ownerLogin, PayoutPaidNotification::class);
    expect(AuditLog::query()->where('action', 'brokers.payout_paid')->sole()->after['utr'])->toBe('UTR12345678');
});

it('mark paid refuses: a draft run, a bad or reused UTR, a line already paid, a bureau suspended since approval', function (): void {
    $a = Broker::factory()->create();
    $b = Broker::factory()->create();
    earnedCommission($a, 60_000);
    earnedCommission($b, 60_000);
    $run = createRun($this->finance);
    $mark = app(MarkPayoutPaid::class);

    expect(fn () => $mark->handle($this->finance, $run, $a->code, 'UTR0000001'))->toThrow(PayoutRefused::class, 'approved');

    app(ApprovePayoutRun::class)->handle($this->finance, $run);
    expect(fn () => $mark->handle($this->finance, $run->refresh(), $a->code, 'abc'))->toThrow(ValidationException::class);
    expect(fn () => $mark->handle($this->finance, $run->refresh(), $a->code, 'UTR-0000001'))->toThrow(ValidationException::class);

    $mark->handle($this->finance, $run->refresh(), $a->code, 'UTR0000001');
    expect(fn () => $mark->handle($this->finance, $run->refresh(), $a->code, 'UTR0000002'))->toThrow(PayoutRefused::class, 'not waiting');
    expect(fn () => $mark->handle($this->finance, $run->refresh(), $b->code, 'UTR0000001'))->toThrow(PayoutRefused::class, 'UTR');

    $b->forceFill(['status' => BrokerStatus::Suspended])->save();
    expect(fn () => $mark->handle($this->finance, $run->refresh(), $b->code, 'UTR0000003'))->toThrow(PayoutRefused::class, $b->code);
    expect(lineOf($run, $b)->status)->toBe(PayoutItemStatus::Pending);
});

it('a line that can no longer be paid is rolled forward with a reason; the run then completes', function (): void {
    $a = Broker::factory()->create();
    $b = Broker::factory()->create();
    earnedCommission($a, 60_000);
    earnedCommission($b, 70_000);
    $run = paidRunExcept($this->finance, $b);

    $b->forceFill(['status' => BrokerStatus::Suspended])->save();
    $item = app(RollPayoutLineForward::class)->handle($this->finance, $run, $b->code, 'Bureau suspended after approval');

    expect($item->status)->toBe(PayoutItemStatus::CarriedForward)
        ->and($item->carried_forward_paise)->toBe(70_000)
        ->and($item->net_paise)->toBe(0)
        ->and($run->refresh()->status)->toBe(PayoutRunStatus::Paid)
        ->and(AuditLog::query()->where('action', 'brokers.payout_rolled_forward')->sole()->reason)->toBe('Bureau suspended after approval');
});

it('B.10: a commission is paid once — the next run never includes a PAID row', function (): void {
    $broker = Broker::factory()->create();
    earnedCommission($broker, 60_000);
    paidRun($this->finance);

    $this->travel(1)->months();
    earnedCommission(Broker::factory()->create(), 60_000);
    $next = createRun($this->finance);

    expect($next->items()->where('broker_id', $broker->id)->exists())->toBeFalse();
});

it('B.19 / R-M13-5: a refund after the payout is clawed back on the next run — once', function (): void {
    $broker = Broker::factory()->create();
    $refunded = earnedCommission($broker, 60_000);
    paidRun($this->finance);
    $refunded->refresh()->forceFill(['status' => ReferralStatus::Void, 'voided_at' => now(), 'void_reason' => ReferralVoidReason::Refund])->save();

    $this->travel(1)->months();
    earnedCommission($broker, 150_000);
    $second = paidRun($this->finance);
    $line = lineOf($second, $broker);
    expect($line->clawback_paise)->toBe(60_000)
        ->and($line->net_paise)->toBe(90_000)
        ->and($refunded->refresh()->clawed_back_item_id)->toBe($line->id);

    $this->travel(1)->months();
    earnedCommission($broker, 60_000);
    $third = createRun($this->finance);
    expect(lineOf($third, $broker)->clawback_paise)->toBe(0);
});

it('B.19: a commission refunded while its line waited for payment is paid with the line, then clawed back', function (): void {
    $broker = Broker::factory()->create();
    $row = earnedCommission($broker, 60_000);
    $run = createRun($this->finance);
    $row->refresh()->forceFill(['status' => ReferralStatus::Void, 'voided_at' => now(), 'void_reason' => ReferralVoidReason::Refund])->save();
    app(ApprovePayoutRun::class)->handle($this->finance, $run);
    app(MarkPayoutPaid::class)->handle($this->finance, $run->refresh(), $broker->code, 'UTR0000009');

    expect($row->refresh()->status)->toBe(ReferralStatus::Void);

    $this->travel(1)->months();
    earnedCommission($broker, 150_000);
    expect(lineOf(createRun($this->finance), $broker)->clawback_paise)->toBe(60_000);
});

it('one open run at a time; a period a PAID run covers needs an audited override reason (B.17)', function (): void {
    earnedCommission(Broker::factory()->create(), 60_000);
    $open = createRun($this->finance);

    expect(fn () => createRun($this->finance))->toThrow(PayoutRefused::class, $open->code);

    app(ApprovePayoutRun::class)->handle($this->finance, $open);
    app(MarkPayoutPaid::class)->handle($this->finance, $open->refresh(), (string) $open->items()->with('broker')->first()?->broker?->code, 'UTR0000010');
    earnedCommission(Broker::factory()->create(), 60_000);

    try {
        createRun($this->finance);
        $this->fail('A paid period was run again without a reason.');
    } catch (PayoutRefused $refused) {
        expect($refused->overridable)->toBeTrue();
    }
    expect(fn () => createRun($this->finance, 'abc'))->toThrow(ValidationException::class);

    $again = createRun($this->finance, 'Late commissions found after the first run');
    expect($again->override_reason)->toBe('Late commissions found after the first run')
        ->and(AuditLog::query()->where('action', 'brokers.payout_run_created')->latest('id')->first()?->reason)->toBe('Late commissions found after the first run');
});

it('validates the period: real dates, start before end, ended by yesterday (IST); nothing to pay is refused', function (string $start, string $end, string $field): void {
    $create = app(CreatePayoutRun::class);

    expect(fn () => $create->handle($this->finance, $start, $end))->toThrow(ValidationException::class);
    try {
        $create->handle($this->finance, $start, $end);
    } catch (ValidationException $invalid) {
        expect($invalid->errors())->toHaveKey($field);
    }
    expect(BrokerPayoutRun::query()->count())->toBe(0);
})->with([
    'not a date' => ['2026-13-01', '2026-13-30', 'periodStart'],
    'end before start' => ['2026-09-30', '2026-09-01', 'periodEnd'],
    'same day' => ['2026-09-01', '2026-09-01', 'periodEnd'],
    'ends today' => fn (): array => [now('Asia/Kolkata')->subDays(3)->toDateString(), now('Asia/Kolkata')->toDateString(), 'periodEnd'],
    'nothing earned' => ['2026-01-01', '2026-01-31', 'periodEnd'],
]);

it('discard: a DRAFT goes, with a reason, and gives back what it claimed; an approved run can’t be discarded', function (): void {
    $broker = Broker::factory()->create();
    $row = earnedCommission($broker, 60_000);
    $run = createRun($this->finance);

    expect(fn () => app(DiscardPayoutRun::class)->handle($this->finance, $run, ''))->toThrow(ValidationException::class);
    app(DiscardPayoutRun::class)->handle($this->finance, $run, 'Wrong period chosen');

    expect(BrokerPayoutRun::query()->count())->toBe(0)
        ->and(BrokerPayoutItem::query()->count())->toBe(0)
        ->and($row->refresh()->payout_item_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'brokers.payout_run_discarded')->sole()->reason)->toBe('Wrong period chosen');

    $again = createRun($this->finance);
    app(ApprovePayoutRun::class)->handle($this->finance, $again);
    expect(fn () => app(DiscardPayoutRun::class)->handle($this->finance, $again->refresh(), 'Too late now'))->toThrow(PayoutRefused::class);
});

it('a held bureau is skipped until released (fraud response "hold commission")', function (): void {
    $broker = Broker::factory()->create();
    earnedCommission($broker, 60_000);
    earnedCommission(Broker::factory()->create(), 60_000);
    app(SetBrokerPayoutHold::class)->handle($this->finance, $broker, true, 'Referral spike under review');

    $run = createRun($this->finance);
    expect($run->items()->where('broker_id', $broker->id)->exists())->toBeFalse();

    app(SetBrokerPayoutHold::class)->handle($this->finance, $broker->refresh(), false, 'Review closed, all fine');
    expect($broker->refresh()->payouts_held_at)->toBeNull()
        ->and(AuditLog::query()->whereIn('action', ['brokers.payouts_held', 'brokers.payouts_released'])->count())->toBe(2);
});

it('every payout step needs brokers.payout (support can view bureaus, not pay them)', function (Closure $step): void {
    earnedCommission(Broker::factory()->create(), 60_000);
    $run = createRun($this->finance);

    expect(fn () => $step(adminWithRole('support'), $run))->toThrow(AuthorizationException::class);
})->with([
    'create' => [fn ($admin) => createRun($admin)],
    'approve' => [fn ($admin, $run) => app(ApprovePayoutRun::class)->handle($admin, $run)],
    'mark paid' => [fn ($admin, $run) => app(MarkPayoutPaid::class)->handle($admin, $run, 'BRK0', 'UTR0000001')],
    'roll forward' => [fn ($admin, $run) => app(RollPayoutLineForward::class)->handle($admin, $run, 'BRK0', 'A good reason')],
    'discard' => [fn ($admin, $run) => app(DiscardPayoutRun::class)->handle($admin, $run, 'A good reason')],
    'hold' => [fn ($admin) => app(SetBrokerPayoutHold::class)->handle($admin, Broker::query()->firstOrFail(), true, 'A good reason')],
]);

it('a bureau code from outside the run is a 404, never another bureau’s line', function (): void {
    earnedCommission(Broker::factory()->create(), 60_000);
    $outsider = Broker::factory()->create();
    $run = createRun($this->finance);
    app(ApprovePayoutRun::class)->handle($this->finance, $run);

    expect(fn () => app(MarkPayoutPaid::class)->handle($this->finance, $run->refresh(), $outsider->code, 'UTR0000001'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
    expect(fn () => app(MarkPayoutPaid::class)->handle($this->finance, $run->refresh(), 'BRK999999', 'UTR0000001'))
        ->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

/** Create and approve last month's run, and pay every line except $skip's. */
function paidRunExcept(App\Models\AdminUser $admin, Broker $skip): BrokerPayoutRun
{
    $run = createRun($admin);
    app(ApprovePayoutRun::class)->handle($admin, $run);
    BrokerPayoutItem::query()->where('run_id', $run->id)->where('broker_id', '!=', $skip->id)->with('broker')->get()
        ->each(fn (BrokerPayoutItem $item) => app(MarkPayoutPaid::class)->handle($admin, $run->refresh(), (string) $item->broker?->code, 'UTR'.random_int(10_000_000, 99_999_999)));

    return $run->refresh();
}

it('does not mark a referral row PAID when it belongs to another bureau', function (): void {
    $a = Broker::factory()->create();
    $b = Broker::factory()->create();
    earnedCommission($a, 60_000);
    $other = earnedCommission($b, 60_000);
    $run = createRun($this->finance);
    app(ApprovePayoutRun::class)->handle($this->finance, $run);

    app(MarkPayoutPaid::class)->handle($this->finance, $run->refresh(), $a->code, 'UTR0000011');

    expect($other->refresh()->status)->toBe(ReferralStatus::Earned)
        ->and(BrokerReferral::query()->where('status', ReferralStatus::Paid)->count())->toBe(1);
});
