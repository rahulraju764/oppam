<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Data\Billing\UsagePeriod;
use App\Domain\Billing\UsagePeriodResolver;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Exceptions\Billing\QuotaExceeded;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\Profile;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * What a profile's plan allows, and how much of it is used (PRD §7.3). The ONLY place limits are
 * enforced — Actions call consume() inside their transaction; the UI only mirrors it.
 *
 * - Plan: the profile's ACTIVE subscription covering "now", else the FREE plan.
 * - Limits come from plan_features (limit_value null = unlimited; is_enabled false = 0 / off).
 *   A missing feature row fails CLOSED (limit 0).
 * - consume() is one INSERT … ON DUPLICATE KEY UPDATE ("add n only while used + n <= limit")
 *   against a unique (profile, entitlement, period) row, so concurrent requests can never both
 *   take the last slot and can't deadlock each other — no read-then-write window.
 */
final class EntitlementService
{
    /** @var array<string, array{plan: Plan, subscription: ?Subscription}> in-request memo by profile id */
    private array $current = [];

    public function __construct(private readonly UsagePeriodResolver $periods) {}

    public function plan(Profile $profile): Plan
    {
        return $this->resolve($profile)['plan'];
    }

    /** null = unlimited. For on/off entitlements use allows(). */
    public function limit(Profile $profile, Entitlement $entitlement): ?int
    {
        $this->assertQuota($entitlement);
        $feature = $this->feature($profile, $entitlement);

        if ($feature === null || ! $feature->is_enabled) {
            return 0;
        }

        return $feature->limit_value;
    }

    public function allows(Profile $profile, Entitlement $entitlement): bool
    {
        if ($entitlement->isFlag()) {
            return (bool) $this->feature($profile, $entitlement)?->is_enabled;
        }

        return $this->limit($profile, $entitlement) !== 0;
    }

    public function used(Profile $profile, Entitlement $entitlement): int
    {
        $this->assertQuota($entitlement);

        return (int) $this->usageRow($profile, $entitlement, $this->period($profile, $entitlement))->value('used');
    }

    /** null = unlimited. */
    public function remaining(Profile $profile, Entitlement $entitlement): ?int
    {
        $limit = $this->limit($profile, $entitlement);

        return $limit === null ? null : max(0, $limit - $this->used($profile, $entitlement));
    }

    /** Advisory (for the UI): the authoritative check is consume(). */
    public function can(Profile $profile, Entitlement $entitlement, int $amount = 1): bool
    {
        $remaining = $this->remaining($profile, $entitlement);

        return $remaining === null || $remaining >= $amount;
    }

    /** Use $amount of a quota, atomically, or throw QuotaExceeded and change nothing. */
    public function consume(Profile $profile, Entitlement $entitlement, int $amount = 1): void
    {
        $this->assertAmount($amount);
        $this->assertCountable($entitlement);
        $limit = $this->limit($profile, $entitlement);
        $period = $this->period($profile, $entitlement);

        if ($limit === 0) {
            throw new QuotaExceeded($entitlement, 0, $period->end);
        }

        if ($limit !== null && $amount > $limit) {
            throw new QuotaExceeded($entitlement, $limit, $period->end);
        }

        if ($this->upsertUsage($profile, $entitlement, $period, $limit, $amount) === 0) {
            throw new QuotaExceeded($entitlement, (int) $limit, $period->end);
        }
    }

    /**
     * Give back usage (e.g. a favourite removed, an interest withdrawn inside its grace). Never below 0.
     * Credits the CURRENT period: usage given back after a reset lands in the new period (callers
     * that care — e.g. a withdrawal — only release within the period they consumed in).
     */
    public function release(Profile $profile, Entitlement $entitlement, int $amount = 1): void
    {
        $this->assertAmount($amount);
        $this->assertCountable($entitlement);
        $period = $this->period($profile, $entitlement);

        $row = fn (): Builder => $this->usageRow($profile, $entitlement, $period);

        // Locked read-then-set (no raw SQL): used is UNSIGNED, so never subtract below zero.
        DB::transaction(function () use ($row, $amount): void {
            $used = (int) $row()->lockForUpdate()->value('used');

            if ($used > 0) {
                $row()->update(['used' => max(0, $used - $amount), 'updated_at' => now()]);
            }
        });
    }

    public function period(Profile $profile, Entitlement $entitlement): UsagePeriod
    {
        $this->assertQuota($entitlement);

        return $this->periods->resolve($entitlement->period(), CarbonImmutable::now(), $this->resolve($profile)['subscription']?->starts_at);
    }

    /** Forget the in-request plan memo (after a subscription changes — P5). */
    public function forget(Profile $profile): void
    {
        unset($this->current[$profile->id]);
    }

    /** @return array{plan: Plan, subscription: ?Subscription} */
    private function resolve(Profile $profile): array
    {
        return $this->current[$profile->id] ??= $this->lookup($profile);
    }

    /** @return array{plan: Plan, subscription: ?Subscription} */
    private function lookup(Profile $profile): array
    {
        $subscription = Subscription::query()
            ->where('profile_id', $profile->id)
            ->currentAt(Carbon::now())
            ->with('plan.features')
            ->latest('ends_at')
            ->first();

        $plan = $subscription !== null
            ? $subscription->plan
            : Plan::query()->with('features')->where('code', PlanCode::Free->value)->firstOrFail();

        return ['plan' => $plan, 'subscription' => $subscription];
    }

    private function feature(Profile $profile, Entitlement $entitlement): ?PlanFeature
    {
        return $this->plan($profile)->features->first(fn (PlanFeature $feature): bool => $feature->entitlement === $entitlement);
    }

    /**
     * ONE statement, so it takes the exclusive lock itself. Update-then-insert or insert-then-update
     * both deadlocked two concurrent callers inside transactions (gap locks on a new period's key,
     * shared locks from INSERT IGNORE on an existing row) — reproduced on MariaDB 10.4 in the P0.6
     * review. Affected rows: 1 = inserted, 2 = incremented, 0 = the row was left unchanged (quota
     * used up). Only bound integers and strings reach the SQL.
     */
    private function upsertUsage(Profile $profile, Entitlement $entitlement, UsagePeriod $period, ?int $limit, int $amount): int
    {
        $now = now()->utc()->format('Y-m-d H:i:s');
        // updated_at is assigned before used: MySQL evaluates the assignments left to right.
        $update = $limit === null
            ? 'updated_at = ?, used = used + ?'
            : 'updated_at = IF(used + ? <= ?, ?, updated_at), used = IF(used + ? <= ?, used + ?, used)';
        $updateBindings = $limit === null
            ? [$now, $amount]
            : [$amount, $limit, $now, $amount, $limit, $amount];

        return DB::affectingStatement(
            'insert into entitlement_usages (id, profile_id, entitlement, period_start, period_end, used, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, ?, ?) on duplicate key update '.$update,
            [
                (string) Str::ulid(),
                $profile->id,
                $entitlement->value,
                $period->start->utc()->format('Y-m-d H:i:s'),
                $period->end?->utc()->format('Y-m-d H:i:s'),
                $amount,
                $now,
                $now,
                ...$updateBindings,
            ],
        );
    }

    private function usageRow(Profile $profile, Entitlement $entitlement, UsagePeriod $period): Builder
    {
        return DB::table('entitlement_usages')
            ->where('profile_id', $profile->id)
            ->where('entitlement', $entitlement->value)
            ->where('period_start', $period->start);
    }

    private function assertQuota(Entitlement $entitlement): void
    {
        if ($entitlement->isFlag()) {
            throw new LogicException("{$entitlement->value} is an on/off entitlement; use allows().");
        }
    }

    /** Per-conversation allowances are counted by their own Action (P4), not in the per-profile table. */
    private function assertCountable(Entitlement $entitlement): void
    {
        $this->assertQuota($entitlement);

        if ($entitlement === Entitlement::FreeRepliesPerConversation) {
            throw new LogicException("{$entitlement->value} is counted per conversation; read limit() and count in the chat Action.");
        }
    }

    private function assertAmount(int $amount): void
    {
        if ($amount < 1) {
            throw new LogicException('Amount must be at least 1.');
        }
    }
}
