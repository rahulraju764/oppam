<?php

declare(strict_types=1);

use App\Actions\Broker\EarnReferralCommission;
use App\Enums\ReferralStatus;
use App\Enums\ReferralVoidReason;
use App\Enums\RefundStatus;
use App\Events\Billing\RefundProcessed;
use App\Listeners\Broker\VoidReferralOnRefund;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerReferral;
use App\Models\Refund;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Notification;

/*
| ★ RefundClawbackTest (P7.2 Done-when; PRD §11A B.10, B.19, R-M13-5): a processed refund of the
| commissioned order — full or partial (owner 2026-10-24) — voids the commission. An EARNED row
| stops counting; a PAID row keeps its payout item so the next payout run (P7.8) claws it back.
| A refund that hasn't gone through, or of another order, changes nothing.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

/** @return array{0: App\Models\User, 1: App\Models\Order} a referred member whose first order earned commission */
function earnedReferral(): array
{
    $member = referredMember(Broker::factory()->create());
    $order = paidOrder($member);
    app(EarnReferralCommission::class)->handle($member->profile->id);
    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned);

    return [$member, $order];
}

it('R-M13-5: a processed full refund voids an EARNED commission', function (): void {
    [$member, $order] = earnedReferral();
    $refund = Refund::factory()->processed()->create(['order_id' => $order->id]);

    RefundProcessed::dispatch($refund->id);

    $row = ledgerRow($member);
    expect($row->status)->toBe(ReferralStatus::Void)
        ->and($row->void_reason)->toBe(ReferralVoidReason::Refund)
        ->and($row->voided_at)->not->toBeNull()
        ->and($row->commission_paise)->toBe(9_990);   // kept for the record; VOID means it no longer counts
});

it('R-M13-5: a partial refund voids the whole commission too (owner 2026-10-24)', function (): void {
    [$member, $order] = earnedReferral();
    $refund = Refund::factory()->processed()->create(['order_id' => $order->id, 'amount_paise' => 10_000, 'ends_plan' => false]);

    RefundProcessed::dispatch($refund->id);

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Void);
});

it('B.19: refunded after the commission was PAID → VOID, and the payout item stays for the clawback', function (): void {
    [$member, $order] = earnedReferral();
    $item = BrokerPayoutItem::factory()->create(['broker_id' => ledgerRow($member)->broker_id]);
    ledgerRow($member)->forceFill(['status' => ReferralStatus::Paid, 'payout_item_id' => $item->id])->save();

    RefundProcessed::dispatch(Refund::factory()->processed()->create(['order_id' => $order->id])->id);

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Void)
        ->and(ledgerRow($member)->payout_item_id)->toBe($item->id);
});

it('a refund that is not PROCESSED (requested, failed, rejected) changes nothing', function (RefundStatus $status): void {
    [$member, $order] = earnedReferral();
    $refund = Refund::factory()->create(['order_id' => $order->id, 'status' => $status]);

    app(VoidReferralOnRefund::class)->handle(new RefundProcessed($refund->id));

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned);
})->with([RefundStatus::Requested, RefundStatus::Processing, RefundStatus::Failed, RefundStatus::Rejected]);

it('a refund of a later, uncommissioned order leaves the first order’s commission alone', function (): void {
    [$member] = earnedReferral();
    $later = paidOrder($member, subtotal: 199_900);

    RefundProcessed::dispatch(Refund::factory()->processed()->create(['order_id' => $later->id])->id);

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned);
});

it('voiding is idempotent: a repeated event and an unknown refund id do nothing more', function (): void {
    [$member, $order] = earnedReferral();
    $refund = Refund::factory()->processed()->create(['order_id' => $order->id]);
    RefundProcessed::dispatch($refund->id);
    $voidedAt = ledgerRow($member)->voided_at;
    $this->travel(5)->minutes();

    RefundProcessed::dispatch($refund->id);
    RefundProcessed::dispatch('01JUNKJUNKJUNKJUNKJUNKJUNK');

    expect(ledgerRow($member)->voided_at?->equalTo($voidedAt))->toBeTrue()
        ->and(BrokerReferral::query()->where('status', ReferralStatus::Void)->count())->toBe(1);
});

it('first order refunded before the commission was evaluated → VOID, never earned, a later order doesn’t earn instead', function (): void {
    $member = referredMember(Broker::factory()->create());
    $first = paidOrder($member, paidAt: now()->subDays(10));
    Refund::factory()->processed()->create(['order_id' => $first->id]);
    paidOrder($member, subtotal: 199_900);

    expect(app(EarnReferralCommission::class)->handle($member->profile->id))->toBeNull()
        ->and(ledgerRow($member)->status)->toBe(ReferralStatus::Void)
        ->and(ledgerRow($member)->order_id)->toBe($first->id)
        ->and(ledgerRow($member)->commission_paise)->toBe(0);
});
