<?php

declare(strict_types=1);

use App\Enums\OrderStatus;
use App\Enums\ProfileStatus;
use App\Enums\ReferralStatus;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\Order;
use App\Models\User;

/*
| P7.2 referral-program test helpers: a member attributed to a bureau (verified code + PENDING
| ledger row, as ResolveBrokerCode leaves it) and paid orders on them.
*/

/** A phone-verified member referred by $broker; ACTIVE (published) unless told otherwise. */
function referredMember(Broker $broker, string $e164 = '+919811100001', ProfileStatus $status = ProfileStatus::Active): User
{
    $member = memberWithPhone($e164, $status);
    $member->profile->forceFill(['broker_id' => $broker->id, 'broker_code_raw' => $broker->code, 'broker_verified' => true])->save();

    BrokerReferral::factory()->create(['broker_id' => $broker->id, 'profile_id' => $member->profile->id, 'status' => ReferralStatus::Pending]);

    return $member->refresh();
}

/** A PAID order on the member's profile; amounts in paise (taxable = subtotal − discount). */
function paidOrder(User $member, int $subtotal = 99_900, int $discount = 0, ?Carbon\CarbonInterface $paidAt = null): Order
{
    $taxable = $subtotal - $discount;
    $gst = intdiv($taxable * 1800 + 5_000, 10_000);

    return Order::factory()->create([
        'profile_id' => $member->profile->id,
        'subtotal_paise' => $subtotal,
        'discount_paise' => $discount,
        'taxable_paise' => $taxable,
        'gst_paise' => $gst,
        'total_paise' => $taxable + $gst,
        'status' => OrderStatus::Paid,
        'paid_at' => $paidAt ?? now(),
    ]);
}

function ledgerRow(User $member): BrokerReferral
{
    return BrokerReferral::query()->where('profile_id', $member->profile->id)->sole();
}
