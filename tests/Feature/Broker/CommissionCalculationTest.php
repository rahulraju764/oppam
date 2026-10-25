<?php

declare(strict_types=1);

use App\Actions\Broker\EarnReferralCommission;
use App\Actions\Moderation\ApproveProfile;
use App\Actions\Profile\SubmitProfile;
use App\Domain\Broker\ReferralCommission;
use App\Enums\BrokerStatus;
use App\Enums\BureauRole;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\ReferralStatus;
use App\Events\Billing\OrderPaid;
use App\Events\Broker\CommissionEarned;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\User;
use App\Notifications\Broker\CommissionEarned as CommissionEarnedNotification;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/*
| ★ CommissionCalculationTest (P7.2 Done-when; PRD §11A B.5, B.10, R-M13-4 / R-M13-8): commission
| on the pre-GST amount actually charged (`taxable_paise`, owner 2026-10-24), the bureau's rate in
| basis points rounded half-up, the FIRST paid order only, ACTIVE profile and ACTIVE bureau only, a
| payment before approval earning on the first approval, idempotent, Owner told live.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Notification::fake();
});

function earn(User $member): ?BrokerReferral
{
    return app(EarnReferralCommission::class)->handle($member->profile->id);
}

it('R-M13-4: the base is the pre-GST amount charged — after a coupon, before GST', function (): void {
    $member = referredMember(Broker::factory()->create());
    paidOrder($member, subtotal: 99_900, discount: 49_950);

    $row = earn($member);

    expect($row?->status)->toBe(ReferralStatus::Earned)
        ->and($row->base_paise)->toBe(49_950)
        ->and($row->commission_bps)->toBe(1000)
        ->and($row->commission_paise)->toBe(4_995);
});

it('F10: Gold for a year ₹11,988 + GST at the default 10 % earns ₹1,198.80', function (): void {
    $member = referredMember(Broker::factory()->create());
    $order = paidOrder($member, subtotal: 1_198_800);

    $row = earn($member);

    expect($row->commission_paise)->toBe(119_880)
        ->and($row->order_id)->toBe($order->id)
        ->and($row->commission()->formatWithSymbol())->toBe('₹1,198.80')
        ->and($row->earned_at)->not->toBeNull();
});

it('the rate is the bureau’s own, snapshotted, and rounded half-up to the paisa', function (int $base, int $bps, int $expected): void {
    $member = referredMember(Broker::factory()->create(['commission_bps' => $bps]));
    paidOrder($member, subtotal: $base);

    expect(earn($member)->commission_paise)->toBe($expected)
        ->and(ReferralCommission::amount($base, $bps))->toBe($expected);
})->with([
    'half rounds up' => [99_905, 1000, 9_991],      // 9990.5 → 9991
    'below half rounds down' => [99_904, 1000, 9_990],
    '7.5 %' => [99_900, 750, 7_493],                // 7492.5 → 7493
    'zero rate' => [99_900, 0, 0],
    'full rate' => [99_900, 10_000, 99_900],
]);

it('a later rate change leaves an earned commission as it was (snapshot)', function (): void {
    $broker = Broker::factory()->create(['commission_bps' => 1000]);
    $member = referredMember($broker);
    paidOrder($member);
    earn($member);

    $broker->forceFill(['commission_bps' => 2000])->save();
    earn($member);

    expect(ledgerRow($member)->commission_paise)->toBe(9_990)->and(ledgerRow($member)->commission_bps)->toBe(1000);
});

it('B.10: the FIRST paid order earns — a second order never adds or replaces commission (one per profile)', function (): void {
    $member = referredMember(Broker::factory()->create());
    $first = paidOrder($member, subtotal: 99_900, paidAt: now()->subDays(40));
    earn($member);

    paidOrder($member, subtotal: 199_900);
    earn($member);

    expect(BrokerReferral::query()->count())->toBe(1)
        ->and(ledgerRow($member)->order_id)->toBe($first->id)
        ->and(ledgerRow($member)->commission_paise)->toBe(9_990);
});

it('nothing to earn: no paid order, or no ledger row (not referred)', function (): void {
    $member = referredMember(Broker::factory()->create());
    expect(earn($member))->toBeNull()->and(ledgerRow($member)->status)->toBe(ReferralStatus::Pending);

    $stranger = memberWithPhone('+919811100099');
    paidOrder($stranger);
    expect(earn($stranger))->toBeNull()->and(BrokerReferral::query()->count())->toBe(1);
});

it('R-M13-8: a bureau that is not ACTIVE earns nothing new (the row stays PENDING)', function (BrokerStatus $status): void {
    $member = referredMember(Broker::factory()->create(['status' => $status]));
    paidOrder($member);

    expect(earn($member))->toBeNull()->and(ledgerRow($member)->status)->toBe(ReferralStatus::Pending);
})->with([BrokerStatus::Suspended, BrokerStatus::Deactivated, BrokerStatus::KycPending]);

it('B.5: paid before the profile was approved → nothing yet; the first approval earns it (owner 2026-10-24)', function (): void {
    $broker = Broker::factory()->create();
    $member = memberThroughStep(6);
    $member->profile->forceFill(['broker_id' => $broker->id, 'broker_code_raw' => $broker->code, 'broker_verified' => true])->save();
    BrokerReferral::factory()->create(['broker_id' => $broker->id, 'profile_id' => $member->profile->id]);
    $item = app(SubmitProfile::class)->handle($member, $member->profile()->firstOrFail());
    paidOrder($member->refresh());

    expect(earn($member))->toBeNull()->and(ledgerRow($member)->status)->toBe(ReferralStatus::Pending);

    app(ApproveProfile::class)->handle(adminWithRole('moderator'), $item);

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned)
        ->and(ledgerRow($member)->commission_paise)->toBe(9_990);
});

it('end to end: a real checkout + captured payment (OrderPaid) earns the commission once', function (): void {
    $member = referredMember(Broker::factory()->create());
    $order = checkoutOrder($member, PlanCode::Gold);

    capturePayment($order);
    OrderPaid::dispatch($order->id);   // a repeated event changes nothing

    expect(ledgerRow($member)->status)->toBe(ReferralStatus::Earned)
        ->and(ledgerRow($member)->base_paise)->toBe($order->refresh()->taxable_paise)
        ->and(ledgerRow($member)->commission_paise)->toBe(ReferralCommission::amount($order->taxable_paise, 1000));
});

it('managed-profile conversions earn the same way (R-M13-4: referral and managed alike)', function (): void {
    $broker = Broker::factory()->create();
    $member = memberWithPhone('+919811100050');
    BrokerReferral::factory()->managed()->create(['broker_id' => $broker->id, 'profile_id' => $member->profile->id]);
    paidOrder($member);

    expect(earn($member)?->commission_paise)->toBe(9_990);
});

it('B.10: only the bureau’s active Owner is told — live on broker-owner.{broker} with code + amount only', function (): void {
    $broker = Broker::factory()->create();
    $owner = bureauLogin(BureauRole::Owner, broker: $broker);
    $manager = bureauLogin(BureauRole::Manager, broker: $broker);
    $member = referredMember($broker);
    paidOrder($member, subtotal: 1_198_800);
    Event::fake([CommissionEarned::class]);

    earn($member);
    earn($member);   // idempotent: told once

    Event::assertDispatchedTimes(CommissionEarned::class, 1);
    Event::assertDispatched(CommissionEarned::class, function (CommissionEarned $event) use ($broker, $member): bool {
        $payload = json_encode($event->broadcastWith());

        return $event->broadcastOn()[0]->name === 'private-broker-owner.'.$broker->id
            && $event->broadcastAs() === 'commission.earned'
            && $event->broadcastWith() === ['profile' => $member->profile->code, 'amount' => '₹1,198.80']
            && ! str_contains((string) $payload, $member->profile->id) && ! str_contains((string) $payload, (string) $member->profile->first_name);
    });
    Notification::assertSentTo($owner, CommissionEarnedNotification::class, fn ($n) => $n->profileCode === $member->profile->code);
    Notification::assertNotSentTo($manager, CommissionEarnedNotification::class);
    Notification::assertNotSentTo($member, CommissionEarnedNotification::class);
});

it('a referred profile that is not yet ACTIVE and has never been published does not earn on payment', function (): void {
    $member = referredMember(Broker::factory()->create(), status: ProfileStatus::PendingReview);
    paidOrder($member);

    expect(earn($member))->toBeNull();
});
