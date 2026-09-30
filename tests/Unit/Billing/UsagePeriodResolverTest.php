<?php

declare(strict_types=1);

use App\Domain\Billing\UsagePeriodResolver;
use App\Enums\EntitlementPeriod;
use Carbon\CarbonImmutable;

/*
| P0.6 — when usage counters reset (PRD §7.3; docs/decisions.md: daily at IST midnight, paid
| monthly on the subscription anniversary, Free monthly on the IST calendar month).
*/

function periodFor(EntitlementPeriod $period, string $nowUtc, ?string $anchorUtc = null): array
{
    $resolved = (new UsagePeriodResolver('Asia/Kolkata'))->resolve(
        $period,
        CarbonImmutable::parse($nowUtc, 'UTC'),
        $anchorUtc === null ? null : CarbonImmutable::parse($anchorUtc, 'UTC'),
    );

    return [$resolved->start->utc()->toDateTimeString(), $resolved->end?->utc()->toDateTimeString()];
}

it('starts the daily period at 00:00 IST, not UTC midnight', function (string $now, string $start, string $end): void {
    expect(periodFor(EntitlementPeriod::Daily, $now))->toBe([$start, $end]);
})->with([
    'just after IST midnight' => ['2026-09-27 18:30:00', '2026-09-27 18:30:00', '2026-09-28 18:30:00'],
    'just before IST midnight' => ['2026-09-27 18:29:59', '2026-09-26 18:30:00', '2026-09-27 18:30:00'],
    'UTC midnight is mid-day IST' => ['2026-09-28 00:00:00', '2026-09-27 18:30:00', '2026-09-28 18:30:00'],
]);

it('gives Free members the IST calendar month', function (string $now, string $start, string $end): void {
    expect(periodFor(EntitlementPeriod::Monthly, $now))->toBe([$start, $end]);
})->with([
    'mid month' => ['2026-09-15 10:00:00', '2026-08-31 18:30:00', '2026-09-30 18:30:00'],
    '1st IST, still 30th UTC' => ['2026-09-30 19:00:00', '2026-09-30 18:30:00', '2026-10-31 18:30:00'],
    'February' => ['2027-02-10 00:00:00', '2027-01-31 18:30:00', '2027-02-28 18:30:00'],
]);

it('gives paid members a month from the subscription anniversary', function (): void {
    expect(periodFor(EntitlementPeriod::Monthly, '2026-09-28 12:00:00', '2026-08-10 09:00:00'))
        ->toBe(['2026-09-10 09:00:00', '2026-10-10 09:00:00'])
        ->and(periodFor(EntitlementPeriod::Monthly, '2026-08-10 09:00:00', '2026-08-10 09:00:00'))
        ->toBe(['2026-08-10 09:00:00', '2026-09-10 09:00:00'])
        ->and(periodFor(EntitlementPeriod::Monthly, '2026-09-10 08:59:59', '2026-08-10 09:00:00'))
        ->toBe(['2026-08-10 09:00:00', '2026-09-10 09:00:00']);
});

it('clamps a month-end anniversary to shorter months without drifting', function (string $now, string $start, string $end): void {
    expect(periodFor(EntitlementPeriod::Monthly, $now, '2027-01-31 06:00:00'))->toBe([$start, $end]);
})->with([
    'February' => ['2027-02-28 07:00:00', '2027-02-28 06:00:00', '2027-03-31 06:00:00'],
    'back to the 31st' => ['2027-03-31 06:00:00', '2027-03-31 06:00:00', '2027-04-30 06:00:00'],
    'April' => ['2027-05-01 00:00:00', '2027-04-30 06:00:00', '2027-05-31 06:00:00'],
]);

it('steps anniversaries on the IST calendar for purchases between 00:00 and 05:30 IST', function (string $anchor, string $now, string $start, string $end): void {
    expect(periodFor(EntitlementPeriod::Monthly, $now, $anchor))->toBe([$start, $end]);
})->with([
    // Bought 1 Mar 02:00 IST (28 Feb 20:30 UTC): renews on the 1st IST, not the 28th/29th.
    'first period' => ['2027-02-28 20:30:00', '2027-03-10 00:00:00', '2027-02-28 20:30:00', '2027-03-31 20:30:00'],
    'next period' => ['2027-02-28 20:30:00', '2027-04-05 00:00:00', '2027-03-31 20:30:00', '2027-04-30 20:30:00'],
    // Bought 31 Mar 03:00 IST (30 Mar 21:30 UTC): April clamps to 30 Apr IST.
    'month-end clamp' => ['2027-03-30 21:30:00', '2027-04-15 00:00:00', '2027-03-30 21:30:00', '2027-04-29 21:30:00'],
    'back to the 31st' => ['2027-03-30 21:30:00', '2027-05-10 00:00:00', '2027-04-29 21:30:00', '2027-05-30 21:30:00'],
]);

it('treats a lifetime cap as one period that never resets', function (): void {
    [$start, $end] = periodFor(EntitlementPeriod::Lifetime, '2026-09-28 12:00:00');

    expect($start)->toBe('2000-01-01 00:00:00')->and($end)->toBeNull()
        ->and(periodFor(EntitlementPeriod::Lifetime, '2031-01-01 00:00:00')[0])->toBe($start);
});

it('refuses to resolve a period for an on/off entitlement', function (): void {
    periodFor(EntitlementPeriod::None, '2026-09-28 12:00:00');
})->throws(LogicException::class);
