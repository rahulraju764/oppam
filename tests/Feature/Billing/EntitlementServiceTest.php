<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Exceptions\Billing\QuotaExceeded;
use App\Models\Plan;
use App\Models\Profile;
use App\Models\Subscription;
use App\Services\Entitlements\EntitlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\DB;

/*
| P0.6 — plan limits enforced server-side (PRD §7.3, CLAUDE.md security rule 5).
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-28 06:00:00', 'UTC'));   // 11:30 IST
});

function entitlements(): EntitlementService
{
    app()->forgetScopedInstances();

    return app(EntitlementService::class);
}

function subscribe(Profile $profile, PlanCode $code, string $start = '2026-09-20 10:00:00'): Subscription
{
    return Subscription::factory()->startingAt($start, 12)->create([
        'profile_id' => $profile->id,
        'plan_id' => Plan::query()->where('code', $code->value)->sole()->id,
    ]);
}

it('gives a profile without a subscription the Free plan limits (§7.3)', function (): void {
    $profile = Profile::factory()->create();
    $service = entitlements();

    expect($service->plan($profile)->code)->toBe(PlanCode::Free)
        ->and($service->limit($profile, Entitlement::LikesPerDay))->toBe(10)
        ->and($service->limit($profile, Entitlement::InterestsPerMonth))->toBe(3)
        ->and($service->limit($profile, Entitlement::ContactViewsPerMonth))->toBe(0)
        ->and($service->allows($profile, Entitlement::ContactViewsPerMonth))->toBeFalse()
        ->and($service->allows($profile, Entitlement::ChatSend))->toBeFalse();
});

it('gives a paid subscriber the plan limits, with null meaning unlimited', function (): void {
    $silver = Profile::factory()->create();
    $gold = Profile::factory()->create();
    subscribe($silver, PlanCode::Silver);
    subscribe($gold, PlanCode::Gold);
    $service = entitlements();

    expect($service->limit($silver, Entitlement::InterestsPerMonth))->toBe(25)
        ->and($service->allows($silver, Entitlement::ChatSend))->toBeTrue()
        ->and($service->limit($gold, Entitlement::LikesPerDay))->toBeNull()
        ->and($service->remaining($gold, Entitlement::LikesPerDay))->toBeNull()
        ->and($service->can($gold, Entitlement::LikesPerDay, 1000))->toBeTrue();

    foreach (range(1, 60) as $_) {
        $service->consume($gold, Entitlement::LikesPerDay);
    }
    expect($service->used($gold, Entitlement::LikesPerDay))->toBe(60);
});

it('falls back to Free once a subscription has expired or been cancelled', function (): void {
    $expired = Profile::factory()->create();
    $cancelled = Profile::factory()->create();
    Subscription::factory()->expired()->create(['profile_id' => $expired->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    Subscription::factory()->cancelled()->create(['profile_id' => $cancelled->id, 'plan_id' => Plan::query()->where('code', PlanCode::Gold->value)->sole()->id]);
    $service = entitlements();

    expect($service->plan($expired)->code)->toBe(PlanCode::Free)
        ->and($service->plan($cancelled)->code)->toBe(PlanCode::Free);
});

it('stops at the limit and throws QuotaExceeded without counting the refused use', function (): void {
    $profile = Profile::factory()->create();
    $service = entitlements();

    foreach (range(1, 3) as $_) {
        $service->consume($profile, Entitlement::InterestsPerMonth);
    }

    try {
        $service->consume($profile, Entitlement::InterestsPerMonth);
        $this->fail('Expected QuotaExceeded');
    } catch (QuotaExceeded $e) {
        // Free = IST calendar month: resets 1 Oct 00:00 IST.
        expect($e->limit)->toBe(3)
            ->and($e->resetsAt?->toDateTimeString())->toBe('2026-09-30 18:30:00');
    }

    expect($service->used($profile, Entitlement::InterestsPerMonth))->toBe(3)
        ->and($service->remaining($profile, Entitlement::InterestsPerMonth))->toBe(0)
        ->and($service->can($profile, Entitlement::InterestsPerMonth))->toBeFalse();
});

it('refuses a use that would overshoot the limit even when some is left', function (): void {
    $profile = Profile::factory()->create();
    $service = entitlements();
    $service->consume($profile, Entitlement::InterestsPerMonth, 2);

    expect(fn () => $service->consume($profile, Entitlement::InterestsPerMonth, 2))->toThrow(QuotaExceeded::class)
        ->and($service->used($profile, Entitlement::InterestsPerMonth))->toBe(2);
});

it('refuses a zero-limit quota outright', function (): void {
    $profile = Profile::factory()->create();

    expect(fn () => entitlements()->consume($profile, Entitlement::ContactViewsPerMonth))->toThrow(QuotaExceeded::class)
        ->and(DB::table('entitlement_usages')->count())->toBe(0);
});

it('never hands out the last slot twice, even when the advisory check was stale', function (): void {
    $profile = Profile::factory()->create();
    $first = entitlements();
    $first->consume($profile, Entitlement::InterestsPerMonth, 2);

    // Two "requests" both saw one remaining before either consumed.
    $second = entitlements();
    expect($first->can($profile, Entitlement::InterestsPerMonth))->toBeTrue()
        ->and($second->can($profile, Entitlement::InterestsPerMonth))->toBeTrue();

    $first->consume($profile, Entitlement::InterestsPerMonth);

    expect(fn () => $second->consume($profile, Entitlement::InterestsPerMonth))->toThrow(QuotaExceeded::class)
        ->and($first->used($profile, Entitlement::InterestsPerMonth))->toBe(3);
});

it('consumes inside a caller transaction and rolls back with it', function (): void {
    $profile = Profile::factory()->create();

    DB::transaction(function () use ($profile): void {
        entitlements()->consume($profile, Entitlement::InterestsPerMonth);
        entitlements()->consume($profile, Entitlement::InterestsPerMonth);
    });

    try {
        DB::transaction(function () use ($profile): void {
            entitlements()->consume($profile, Entitlement::InterestsPerMonth);
            throw new RuntimeException('the Action failed after consuming');
        });
    } catch (RuntimeException) {
    }

    expect(entitlements()->used($profile, Entitlement::InterestsPerMonth))->toBe(2);
});

it('refuses to count a per-conversation allowance in the per-profile table', function (): void {
    $profile = Profile::factory()->create();

    expect(entitlements()->limit($profile, Entitlement::FreeRepliesPerConversation))->toBe(1)
        ->and(fn () => entitlements()->consume($profile, Entitlement::FreeRepliesPerConversation))->toThrow(LogicException::class)
        ->and(fn () => entitlements()->release($profile, Entitlement::FreeRepliesPerConversation))->toThrow(LogicException::class);
});

it('resets the daily like count at 00:00 IST', function (): void {
    $profile = Profile::factory()->create();
    $this->travelTo(CarbonImmutable::parse('2026-09-28 18:29:00', 'UTC'));   // 23:59 IST
    foreach (range(1, 10) as $_) {
        entitlements()->consume($profile, Entitlement::LikesPerDay);
    }
    expect(entitlements()->can($profile, Entitlement::LikesPerDay))->toBeFalse();

    $this->travelTo(CarbonImmutable::parse('2026-09-28 18:30:00', 'UTC'));   // 00:00 IST next day

    expect(entitlements()->remaining($profile, Entitlement::LikesPerDay))->toBe(10);
    entitlements()->consume($profile, Entitlement::LikesPerDay);
    expect(entitlements()->used($profile, Entitlement::LikesPerDay))->toBe(1);
});

it('resets a Free monthly quota on the 1st of the IST month', function (): void {
    $profile = Profile::factory()->create();
    entitlements()->consume($profile, Entitlement::InterestsPerMonth, 3);

    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:29:59', 'UTC'));   // 30 Sep 23:59:59 IST
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-30 18:30:00', 'UTC'));   // 1 Oct 00:00 IST
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(3);
});

it('resets a paid monthly quota on the subscription anniversary, not the 1st', function (): void {
    $profile = Profile::factory()->create();
    subscribe($profile, PlanCode::Silver, '2026-08-15 10:00:00');
    entitlements()->consume($profile, Entitlement::InterestsPerMonth, 25);   // period 15 Sep - 15 Oct

    $this->travelTo(CarbonImmutable::parse('2026-10-01 00:00:00', 'UTC'));
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00:00', 'UTC'));
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(25);
});

it('resets a 31st anniversary on the last day of a shorter month', function (): void {
    $profile = Profile::factory()->create();
    Subscription::factory()->startingAt('2026-08-31 10:00:00', 12)->create([
        'profile_id' => $profile->id,
        'plan_id' => Plan::query()->where('code', PlanCode::Silver->value)->sole()->id,
    ]);
    entitlements()->consume($profile, Entitlement::InterestsPerMonth, 25);   // period 31 Aug - 30 Sep

    $this->travelTo(CarbonImmutable::parse('2026-09-30 09:59:59', 'UTC'));
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-09-30 10:00:00', 'UTC'));
    expect(entitlements()->remaining($profile, Entitlement::InterestsPerMonth))->toBe(25)
        ->and(entitlements()->period($profile, Entitlement::InterestsPerMonth)->end?->toDateTimeString())->toBe('2026-10-31 10:00:00');
});

it('keeps a lifetime cap across months and gives usage back on release', function (): void {
    $profile = Profile::factory()->create();
    entitlements()->consume($profile, Entitlement::FavoritesTotal, 25);

    $this->travelTo(CarbonImmutable::parse('2027-03-01 00:00:00', 'UTC'));
    expect(entitlements()->can($profile, Entitlement::FavoritesTotal))->toBeFalse();

    entitlements()->release($profile, Entitlement::FavoritesTotal, 2);
    expect(entitlements()->remaining($profile, Entitlement::FavoritesTotal))->toBe(2);

    entitlements()->release($profile, Entitlement::FavoritesTotal, 100);
    expect(entitlements()->used($profile, Entitlement::FavoritesTotal))->toBe(0);
});

it('keeps each profile\'s usage separate', function (): void {
    [$a, $b] = Profile::factory()->count(2)->create();
    entitlements()->consume($a, Entitlement::InterestsPerMonth, 3);

    expect(entitlements()->remaining($b, Entitlement::InterestsPerMonth))->toBe(3);
});

it('rejects quota calls on on/off entitlements and non-positive amounts', function (): void {
    $profile = Profile::factory()->create();

    expect(fn () => entitlements()->limit($profile, Entitlement::ChatSend))->toThrow(LogicException::class)
        ->and(fn () => entitlements()->consume($profile, Entitlement::ChatSend))->toThrow(LogicException::class)
        ->and(fn () => entitlements()->consume($profile, Entitlement::LikesPerDay, 0))->toThrow(LogicException::class)
        ->and(fn () => entitlements()->release($profile, Entitlement::LikesPerDay, -1))->toThrow(LogicException::class);
});

it('fails closed when a plan has no row for an entitlement', function (): void {
    $profile = Profile::factory()->create();
    Plan::query()->where('code', PlanCode::Free->value)->sole()->features()->where('entitlement', 'likes_per_day')->delete();

    expect(entitlements()->limit($profile, Entitlement::LikesPerDay))->toBe(0)
        ->and(fn () => entitlements()->consume($profile, Entitlement::LikesPerDay))->toThrow(QuotaExceeded::class);
});
