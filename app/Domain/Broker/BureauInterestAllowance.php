<?php

declare(strict_types=1);

namespace App\Domain\Broker;

use App\Exceptions\Broker\ManagedProfileRefused;
use App\Models\Broker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The bureau's monthly interest allowance for its managed clients (§11A B.6, R-M13-35): every
 * interest a bureau sends on a client's behalf counts against `brokers.interest_allowance_monthly`
 * (default 100, set in A07) — one pool per bureau, not per client — reset each calendar month in
 * IST. Counted in `broker_interest_usage`; consume() runs inside the interest's transaction so a
 * refused or failed send never spends one.
 */
final class BureauInterestAllowance
{
    private const TABLE = 'broker_interest_usage';

    public function limit(Broker $broker): int
    {
        return $broker->interest_allowance_monthly;
    }

    public function used(Broker $broker): int
    {
        return (int) DB::table(self::TABLE)->where('broker_id', $broker->id)->where('period', self::period())->value('used');
    }

    public function remaining(Broker $broker): int
    {
        return max(0, $this->limit($broker) - $this->used($broker));
    }

    /**
     * Spend one interest of this month's allowance — atomically: the counter only moves while it
     * is below the limit, so two staff sending at once can't overshoot it.
     *
     * @throws ManagedProfileRefused the allowance is used up
     */
    public function consume(Broker $broker): void
    {
        $period = self::period();

        DB::table(self::TABLE)->insertOrIgnore([
            'id' => (string) Str::ulid(), 'broker_id' => $broker->id, 'period' => $period, 'used' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $updated = DB::table(self::TABLE)
            ->where('broker_id', $broker->id)->where('period', $period)
            ->where('used', '<', $this->limit($broker))
            ->increment('used', 1, ['updated_at' => now()]);

        if ($updated === 0) {
            throw ManagedProfileRefused::allowanceUsed($this->limit($broker));
        }
    }

    /** The current IST month, e.g. 2026-10. */
    public static function period(): string
    {
        return now((string) config('oppam.display_timezone'))->format('Y-m');
    }
}
