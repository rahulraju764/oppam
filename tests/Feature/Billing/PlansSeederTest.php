<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Models\Plan;
use App\Queries\Billing\PlanCatalog;
use Database\Seeders\PlansSeeder;

/*
| P0.4 — plans, prices (paise) and the PRD §7.3 entitlement matrix.
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
});

function feature(PlanCode $plan, Entitlement $entitlement): array
{
    $row = Plan::query()->where('code', $plan->value)->firstOrFail()
        ->features()->where('entitlement', $entitlement->value)->firstOrFail();

    return ['limit' => $row->limit_value, 'enabled' => $row->is_enabled];
}

it('prices the paid plans in paise and never sells Free', function (): void {
    $prices = Plan::query()->with('prices')->get()->mapWithKeys(fn (Plan $plan): array => [
        $plan->code->value => $plan->prices->first()?->price_paise,
    ]);

    expect($prices->all())->toBe(['FREE' => null, 'SILVER' => 49_900, 'GOLD' => 99_900, 'DIAMOND' => 199_900])
        ->and(Plan::query()->where('code', 'FREE')->firstOrFail()->is_purchasable)->toBeFalse();
});

it('seeds the PRD §7.3 quotas (null = unlimited)', function (PlanCode $plan, Entitlement $entitlement, ?int $limit): void {
    expect(feature($plan, $entitlement)['limit'])->toBe($limit);
})->with([
    [PlanCode::Free, Entitlement::LikesPerDay, 10],
    [PlanCode::Silver, Entitlement::LikesPerDay, 50],
    [PlanCode::Gold, Entitlement::LikesPerDay, null],
    [PlanCode::Free, Entitlement::FavoritesTotal, 25],
    [PlanCode::Silver, Entitlement::FavoritesTotal, 200],
    [PlanCode::Free, Entitlement::InterestsPerMonth, 3],
    [PlanCode::Silver, Entitlement::InterestsPerMonth, 25],
    [PlanCode::Gold, Entitlement::InterestsPerMonth, 100],
    [PlanCode::Diamond, Entitlement::InterestsPerMonth, null],
    [PlanCode::Free, Entitlement::ContactViewsPerMonth, 0],
    [PlanCode::Silver, Entitlement::ContactViewsPerMonth, 10],
    [PlanCode::Gold, Entitlement::ContactViewsPerMonth, 50],
    [PlanCode::Diamond, Entitlement::ContactViewsPerMonth, null],
    [PlanCode::Free, Entitlement::FreeRepliesPerConversation, 1],
]);

it('seeds the PRD §7.3 feature flags', function (PlanCode $plan, Entitlement $entitlement, bool $enabled): void {
    expect(feature($plan, $entitlement)['enabled'])->toBe($enabled);
})->with([
    [PlanCode::Free, Entitlement::ChatSend, false],          // read-only + one free reply (R-M07-3)
    [PlanCode::Silver, Entitlement::ChatSend, true],
    [PlanCode::Free, Entitlement::SeeWhoLikedMe, false],     // count + blurred list
    [PlanCode::Silver, Entitlement::SeeWhoLikedMe, true],
    [PlanCode::Silver, Entitlement::SeeWhoViewedMe, false],  // count only
    [PlanCode::Gold, Entitlement::SeeWhoViewedMe, true],
    [PlanCode::Silver, Entitlement::SearchHighlight, false],
    [PlanCode::Gold, Entitlement::SearchHighlight, true],
    [PlanCode::Gold, Entitlement::TopPlacement, false],
    [PlanCode::Diamond, Entitlement::TopPlacement, true],
]);

it('seeds every entitlement for every plan exactly once, and is idempotent', function (): void {
    $this->seed(PlansSeeder::class);

    Plan::query()->with('features')->get()->each(function (Plan $plan): void {
        expect($plan->features->pluck('entitlement')->map->value->sort()->values()->all())
            ->toBe(collect(Entitlement::cases())->map->value->sort()->values()->all());
    });

    expect(Plan::query()->count())->toBe(4);
});

it('builds the pricing cards for purchasable plans from the database', function (): void {
    $cards = app(PlanCatalog::class)->cards(ctaUrl: null);

    expect(collect($cards)->pluck('key')->all())->toBe(['SILVER', 'GOLD', 'DIAMOND'])
        ->and($cards[1]->isFeatured)->toBeTrue()
        ->and($cards[1]->badge)->toBe('Most Popular')
        ->and($cards[2]->yearlyPrice->format())->toBe('23,988');
});

it('hides a deactivated plan from the pricing cards', function (): void {
    Plan::query()->where('code', 'SILVER')->update(['is_active' => false]);

    expect(collect(app(PlanCatalog::class)->cards(ctaUrl: null))->pluck('key')->all())->toBe(['GOLD', 'DIAMOND']);
});

it('never overwrites an A06 price, limit or plan edit when the seeder runs again', function (): void {
    $gold = Plan::query()->where('code', 'GOLD')->firstOrFail();
    $gold->update(['name' => 'Gold Plus', 'is_active' => false]);
    $gold->prices()->update(['price_paise' => 89_900]);
    $gold->features()->where('entitlement', Entitlement::InterestsPerMonth->value)->update(['limit_value' => 150]);

    $this->seed(PlansSeeder::class);

    $gold->refresh();
    expect($gold->name)->toBe('Gold Plus')
        ->and($gold->is_active)->toBeFalse()
        ->and($gold->prices()->value('price_paise'))->toBe(89_900)
        ->and(feature(PlanCode::Gold, Entitlement::InterestsPerMonth)['limit'])->toBe(150);
});

it('shows an empty state instead of a blank pricing row when no plan is purchasable', function (): void {
    Plan::query()->update(['is_active' => false]);

    $this->get('/plans')->assertOk()->assertSee('Membership plans are being updated');
});
