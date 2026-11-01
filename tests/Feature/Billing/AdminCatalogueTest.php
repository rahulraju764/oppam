<?php

declare(strict_types=1);

use App\Actions\Admin\Billing\ChangePlanAvailability;
use App\Actions\Admin\Billing\ManageCoupon;
use App\Actions\Admin\Billing\SavePlan;
use App\Actions\Admin\Billing\SavePlanEntitlements;
use App\Data\Billing\CouponData;
use App\Data\Billing\PlanEditData;
use App\Domain\Billing\PlanConsistency;
use App\Enums\CouponType;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Events\Billing\EntitlementsChanged;
use App\Exceptions\Admin\BillingRefused;
use App\Jobs\Billing\GenerateInvoicePdf;
use App\Models\AuditLog;
use App\Models\Coupon;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Queries\Billing\PlanCatalog;
use App\Services\Entitlements\EntitlementService;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

/*
| P5.3 — A06 plans and coupons. A price change reaches new orders only; an entitlement change applies
| at once and tells the plan's running members; never delete what is referenced.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Queue::fake([GenerateInvoicePdf::class]);
    $this->finance = adminWithRole('finance');
    $this->gold = Plan::query()->where('code', PlanCode::Gold->value)->with('prices')->sole();
});

function goldCard(Plan $plan, array $overrides = []): PlanEditData
{
    return new PlanEditData(...[
        'name' => $plan->name, 'badge' => $plan->badge, 'isFeatured' => $plan->is_featured, 'isPurchasable' => true,
        'displayFeatures' => $plan->display_features,
        'prices' => $plan->prices->mapWithKeys(fn ($p): array => [$p->duration_months => ['rupees' => intdiv($p->price_paise, 100), 'active' => $p->is_active]])->all(),
        ...$overrides,
    ]);
}

function couponData(array $overrides = []): CouponData
{
    return new CouponData(...[
        'code' => ' diwali20 ', 'type' => CouponType::Percent, 'value' => 20, 'maxDiscountPaise' => 50_000, 'minOrderPaise' => null,
        'maxRedemptions' => null, 'perMemberLimit' => 1, 'firstTimeOnly' => false, 'planCodes' => null, 'startsAt' => null, 'endsAt' => null,
        ...$overrides,
    ]);
}

it('A06: a price change reaches new orders only — an open order keeps its amount', function (): void {
    $open = checkoutOrder(groom(), PlanCode::Gold);
    $paid = $open->total_paise;
    $prices = goldCard($this->gold)->prices;
    $prices[1]['rupees'] = 1_500;

    app(SavePlan::class)->handle($this->finance, $this->gold, goldCard($this->gold, ['prices' => $prices]));

    expect($open->refresh()->total_paise)->toBe($paid)
        ->and(checkoutOrder(bride(), PlanCode::Gold)->taxable_paise)->toBe(150_000)
        ->and(AuditLog::query()->where('action', 'billing.plan_saved')->sole()->actor_id)->toBe($this->finance->id);
});

it('A06: one featured plan at a time; bad prices and names are refused with nothing saved', function (): void {
    $silver = Plan::query()->where('code', PlanCode::Silver->value)->with('prices')->sole();

    app(SavePlan::class)->handle($this->finance, $silver, goldCard($silver, ['isFeatured' => true]));

    expect($silver->refresh()->is_featured)->toBeTrue()->and($this->gold->refresh()->is_featured)->toBeFalse();

    $name = $this->gold->name;
    $free = goldCard($this->gold)->prices;
    $free[1]['rupees'] = 0;
    expect(fn () => app(SavePlan::class)->handle($this->finance, $this->gold, goldCard($this->gold, ['prices' => $free])))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePlan::class)->handle($this->finance, $this->gold, goldCard($this->gold, ['name' => 'x'])))->toThrow(ValidationException::class)
        ->and(fn () => app(SavePlan::class)->handle($this->finance, $this->gold, goldCard($this->gold, ['prices' => [1 => ['rupees' => 999, 'active' => false]]])))->toThrow(ValidationException::class)
        ->and($this->gold->refresh()->name)->toBe($name);
});

it('A06 permission: billing.plans.edit for every plan change; support (view only) is refused', function (): void {
    $support = adminWithRole('support');

    expect(fn () => app(SavePlan::class)->handle($support, $this->gold, goldCard($this->gold)))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SavePlanEntitlements::class)->handle($support, $this->gold, []))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangePlanAvailability::class)->setActive($support, $this->gold, false, 'Not allowed'))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ChangePlanAvailability::class)->delete($support, $this->gold, 'Not allowed'))->toThrow(AuthorizationException::class);
});

it('A06: an entitlement change applies at once and tells members running the plan, flags re-synced', function (): void {
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Gold));
    $outsider = bride();
    expect(app(EntitlementService::class)->limit($member->profile->refresh(), Entitlement::LikesPerDay))->not->toBe(7);
    $rules = PlanFeature::query()->where('plan_id', $this->gold->id)->get()
        ->mapWithKeys(fn (PlanFeature $f): array => [$f->entitlement->value => ['enabled' => $f->is_enabled, 'limit' => $f->limit_value]])->all();
    $rules[Entitlement::LikesPerDay->value] = ['enabled' => true, 'limit' => 7];
    Event::fake([EntitlementsChanged::class]);

    app(SavePlanEntitlements::class)->handle($this->finance, $this->gold, $rules);
    app(EntitlementService::class)->forget($member->profile);

    expect(app(EntitlementService::class)->limit($member->profile->refresh(), Entitlement::LikesPerDay))->toBe(7);
    Event::assertDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $member->id && $e->planCode === 'GOLD');
    Event::assertNotDispatched(EntitlementsChanged::class, fn (EntitlementsChanged $e): bool => $e->userId === $outsider->id);
    expect(AuditLog::query()->where('action', 'billing.plan_entitlements_saved')->sole()->after[Entitlement::LikesPerDay->value]['limit'])->toBe(7);
});

it('A06 entitlements: a negative limit is refused; an empty limit means unlimited', function (): void {
    expect(fn () => app(SavePlanEntitlements::class)->handle($this->finance, $this->gold, [Entitlement::LikesPerDay->value => ['enabled' => true, 'limit' => -1]]))->toThrow(ValidationException::class);

    app(SavePlanEntitlements::class)->handle($this->finance, $this->gold, [Entitlement::LikesPerDay->value => ['enabled' => true, 'limit' => null]]);
    expect(PlanFeature::query()->where('plan_id', $this->gold->id)->where('entitlement', Entitlement::LikesPerDay->value)->sole()->limit_value)->toBeNull();
});

it('A06 consistency warning: a number or feature on the card that isn’t what is enforced', function (): void {
    $check = new PlanConsistency;
    $rules = [
        Entitlement::LikesPerDay->value => ['enabled' => true, 'limit' => 50],
        Entitlement::ChatSend->value => ['enabled' => false, 'limit' => null],
        Entitlement::InterestsPerMonth->value => ['enabled' => true, 'limit' => null],
    ];

    expect($check->warnings(['50 likes a day'], $rules))->toBe([])
        ->and($check->warnings(['Unlimited interests'], $rules))->toBe([])
        ->and($check->warnings(['100 likes a day'], $rules))->toHaveCount(1)
        ->and($check->warnings(['Chat with your matches'], $rules))->toHaveCount(1)
        ->and($check->warnings(['20 interests a month'], $rules))->toHaveCount(1)
        ->and($check->warnings(['A friendly team'], $rules))->toBe([]);
});

it('A06 "plan deactivate (never delete if referenced)": a deactivated plan leaves the catalogue; a referenced plan can’t be deleted; an unused one can', function (): void {
    $change = app(ChangePlanAvailability::class);
    checkoutOrder(groom(), PlanCode::Gold);

    expect(fn () => $change->delete($this->finance, $this->gold, 'Tidying up plans'))->toThrow(BillingRefused::class, 'Deactivate');

    $change->setActive($this->finance, $this->gold, false, 'Replaced by a new offer');
    expect(collect(app(PlanCatalog::class)->cards(null))->pluck('key'))->not->toContain('gold')
        ->and(AuditLog::query()->where('action', 'billing.plan_deactivated')->sole()->reason)->toBe('Replaced by a new offer');

    $unused = Plan::query()->where('code', PlanCode::Diamond->value)->sole();
    $change->delete($this->finance, $unused, 'Never sold this one');
    expect(Plan::query()->whereKey($unused->id)->exists())->toBeFalse();
});

it('A06: the Free plan can’t be deactivated or deleted; a reason is required', function (): void {
    $free = Plan::query()->where('code', PlanCode::Free->value)->sole();
    $change = app(ChangePlanAvailability::class);

    expect(fn () => $change->setActive($this->finance, $free, false, 'Trying anyway'))->toThrow(BillingRefused::class)
        ->and(fn () => $change->delete($this->finance, $free, 'Trying anyway'))->toThrow(BillingRefused::class)
        ->and(fn () => $change->setActive($this->finance, $this->gold, false, ''))->toThrow(ValidationException::class);
});

it('A06 coupons: created normalised and active, audited; every rule refused with nothing saved', function (): void {
    $manage = app(ManageCoupon::class);
    $coupon = $manage->save($this->finance, null, couponData());

    expect($coupon->code)->toBe('DIWALI20')->and($coupon->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'billing.coupon_created')->sole()->actor_id)->toBe($this->finance->id);

    foreach ([
        'duplicate code' => ['code' => 'DIWALI20'],
        'short code' => ['code' => 'A'],
        'percent over 100' => ['code' => 'NEWONE', 'value' => 101],
        'fixed under ₹1' => ['code' => 'NEWONE', 'type' => CouponType::Fixed, 'value' => 50],
        'per member 0' => ['code' => 'NEWONE', 'perMemberLimit' => 0],
        'ends before start' => ['code' => 'NEWONE', 'startsAt' => now()->toImmutable(), 'endsAt' => now()->subDay()->toImmutable()],
        'the Free plan' => ['code' => 'NEWONE', 'planCodes' => ['FREE']],
    ] as $case => $overrides) {
        expect(fn () => $manage->save($this->finance, null, couponData($overrides)))->toThrow(ValidationException::class);
    }
    expect(Coupon::query()->count())->toBe(1);
});

it('A06 coupons: a used one keeps its code and can’t be deleted, only deactivated; deactivated, checkout refuses it; billing.coupons.edit only', function (): void {
    $manage = app(ManageCoupon::class);
    $coupon = $manage->save($this->finance, null, couponData());
    $member = groom();
    capturePayment(checkoutOrder($member, PlanCode::Gold, 1, 'DIWALI20'));
    $coupon->refresh();
    expect($coupon->redemptions_count)->toBe(1);

    expect(fn () => $manage->save($this->finance, $coupon, couponData(['code' => 'OTHER20'])))->toThrow(ValidationException::class)
        ->and(fn () => $manage->save($this->finance, $coupon, couponData(['maxRedemptions' => 0])))->toThrow(ValidationException::class)
        ->and(fn () => $manage->delete($this->finance, $coupon))->toThrow(BillingRefused::class)
        ->and(fn () => $manage->save(adminWithRole('support'), null, couponData(['code' => 'NEWONE'])))->toThrow(AuthorizationException::class);

    $manage->setActive($this->finance, $coupon, false);
    expect(fn () => checkoutOrder(bride(), PlanCode::Gold, 1, 'DIWALI20'))->toThrow(Exception::class);

    $unused = $manage->save($this->finance, null, couponData(['code' => 'UNUSED10']));
    $manage->delete($this->finance, $unused);
    expect(Coupon::query()->whereKey($unused->id)->exists())->toBeFalse();
});
