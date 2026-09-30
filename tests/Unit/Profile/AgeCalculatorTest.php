<?php

declare(strict_types=1);

use App\Domain\Profile\AgeCalculator;
use Carbon\CarbonImmutable;

it('counts completed years', function (string $dob, string $today, int $age): void {
    expect(AgeCalculator::ageOn(CarbonImmutable::parse($dob), CarbonImmutable::parse($today)))->toBe($age);
})->with([
    'day before birthday' => ['2000-06-15', '2026-06-14', 25],
    'on birthday' => ['2000-06-15', '2026-06-15', 26],
    'day after birthday' => ['2000-06-15', '2026-06-16', 26],
    'born today' => ['2026-09-28', '2026-09-28', 0],
    'leap-day birthday, non-leap year, 28 Feb' => ['2000-02-29', '2025-02-28', 24],
    'leap-day birthday, non-leap year, 1 Mar' => ['2000-02-29', '2025-03-01', 25],
    'leap-day birthday, leap year' => ['2000-02-29', '2024-02-29', 24],
    'year end' => ['1999-12-31', '2025-12-31', 26],
]);

it('turns a member a year older at midnight IST, not midnight UTC', function (): void {
    $dob = CarbonImmutable::parse('2005-09-28');

    // 18:29 UTC on 27 Sep is 23:59 IST on 27 Sep — still 20. 18:30 UTC is 00:00 IST on the 28th — 21.
    $justBefore = CarbonImmutable::parse('2026-09-27 18:29:00', 'UTC')->setTimezone('Asia/Kolkata');
    $atMidnight = CarbonImmutable::parse('2026-09-27 18:30:00', 'UTC')->setTimezone('Asia/Kolkata');

    expect(AgeCalculator::ageOn($dob, $justBefore))->toBe(20)
        ->and(AgeCalculator::ageOn($dob, $atMidnight))->toBe(21);
});

it('refuses a date of birth in the future', function (): void {
    AgeCalculator::ageOn(CarbonImmutable::parse('2030-01-01'), CarbonImmutable::parse('2026-01-01'));
})->throws(InvalidArgumentException::class);
