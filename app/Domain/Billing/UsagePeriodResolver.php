<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Data\Billing\UsagePeriod;
use App\Enums\EntitlementPeriod;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * When a usage counter resets (PRD §7.3, docs/decisions.md):
 * - Daily   → at 00:00 IST.
 * - Monthly → on the subscription anniversary (IST calendar) for a paid plan (a plan bought on the 31st resets on
 *             the last day of shorter months — no overflow into the next month); on the 1st of
 *             the calendar month (IST) for Free, which has no subscription.
 * - Lifetime→ never (a cap on a running total, e.g. favourites).
 * Pure: the caller supplies "now" and the subscription start. Returned instants are UTC.
 */
final class UsagePeriodResolver
{
    // Not 1970-01-01 00:00:00: a MySQL TIMESTAMP can't hold anything before 00:00:01 UTC that day.
    private const LIFETIME_START = '2000-01-01 00:00:00';

    public function __construct(private readonly string $timezone = 'Asia/Kolkata') {}

    public function resolve(EntitlementPeriod $period, CarbonInterface $now, ?CarbonInterface $subscriptionStart = null): UsagePeriod
    {
        return match ($period) {
            EntitlementPeriod::Daily => $this->daily($now),
            EntitlementPeriod::Monthly => $subscriptionStart !== null
                ? $this->anniversaryMonth($now, $subscriptionStart)
                : $this->calendarMonth($now),
            EntitlementPeriod::Lifetime => new UsagePeriod(CarbonImmutable::parse(self::LIFETIME_START, 'UTC'), null),
            EntitlementPeriod::None => throw new LogicException('On/off entitlements have no usage period.'),
        };
    }

    private function daily(CarbonInterface $now): UsagePeriod
    {
        $start = CarbonImmutable::instance($now)->setTimezone($this->timezone)->startOfDay();

        return new UsagePeriod($start->utc(), $start->addDay()->utc());
    }

    private function calendarMonth(CarbonInterface $now): UsagePeriod
    {
        $start = CarbonImmutable::instance($now)->setTimezone($this->timezone)->startOfMonth();

        return new UsagePeriod($start->utc(), $start->addMonthNoOverflow()->utc());
    }

    private function anniversaryMonth(CarbonInterface $now, CarbonInterface $subscriptionStart): UsagePeriod
    {
        // Month stepping on the IST calendar: a plan bought at 02:00 IST on 1 Mar renews on the
        // 1st (IST) — on the UTC calendar that instant is still 28 Feb and would drift to the 28th/29th.
        $anchor = CarbonImmutable::instance($subscriptionStart)->setTimezone($this->timezone);
        $now = CarbonImmutable::instance($now)->setTimezone($this->timezone);

        if ($now->lt($anchor)) {
            return new UsagePeriod($anchor->utc(), $anchor->addMonthsNoOverflow(1)->utc());
        }

        // Months are counted from the anchor each time (never chained), so a 31st anchor gives
        // 31 Jan → 28/29 Feb → 31 Mar, not 31 Jan → 28 Feb → 28 Mar.
        $months = (int) $anchor->diffInMonths($now);
        $start = $anchor->addMonthsNoOverflow($months);

        if ($start->gt($now)) {
            $start = $anchor->addMonthsNoOverflow(--$months);
        }

        $end = $anchor->addMonthsNoOverflow($months + 1);

        if ($end->lte($now)) {
            $start = $end;
            $end = $anchor->addMonthsNoOverflow($months + 2);
        }

        return new UsagePeriod($start->utc(), $end->utc());
    }
}
