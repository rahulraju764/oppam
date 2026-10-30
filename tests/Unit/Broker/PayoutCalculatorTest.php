<?php

declare(strict_types=1);

use App\Domain\Broker\PayoutCalculator;

/*
| P7.8 — payout maths (§11A B.10, R-A07-4, R-A07-5, B.19; decisions 2026-10-29): balance = gross −
| clawback + carried in; TDS half-up on a positive balance; under the minimum (or ≤ 0) the whole
| balance before TDS rolls forward; a paid line above the threshold needs a second approver.
*/

function calculator(int $minimum = 50_000, int $second = 5_000_000, int $tds = 0): PayoutCalculator
{
    return new PayoutCalculator($minimum, $second, $tds);
}

it('pays gross − clawback + carried in when it reaches the minimum', function (): void {
    $line = calculator()->line(80_000, 10_000, 5_000);

    expect($line->payable)->toBeTrue()
        ->and($line->netPaise)->toBe(75_000)
        ->and($line->carriedForwardPaise)->toBe(0)
        ->and($line->tdsPaise)->toBe(0)
        ->and($line->needsSecondApprover)->toBeFalse();
});

it('R-A07-5: a balance under ₹500 rolls forward whole, nothing paid', function (int $gross, int $clawback, int $carried, int $carriedForward): void {
    $line = calculator()->line($gross, $clawback, $carried);

    expect($line->payable)->toBeFalse()
        ->and($line->netPaise)->toBe(0)
        ->and($line->tdsPaise)->toBe(0)
        ->and($line->carriedForwardPaise)->toBe($carriedForward);
})->with([
    'just under the minimum' => [49_999, 0, 0, 49_999],
    'clawback larger than earned' => [10_000, 30_000, 0, -20_000],
    'a negative carried balance eats this month' => [40_000, 0, -5_000, 35_000],
    'nothing at all' => [0, 0, 0, 0],
]);

it('R-A07-5: exactly the minimum is paid', function (): void {
    expect(calculator()->line(50_000, 0, 0)->payable)->toBeTrue();
});

it('R-A07-4: a line above ₹50,000 needs a second approver; exactly ₹50,000 does not', function (): void {
    expect(calculator()->line(5_000_001, 0, 0)->needsSecondApprover)->toBeTrue()
        ->and(calculator()->line(5_000_000, 0, 0)->needsSecondApprover)->toBeFalse();
});

it('B.10 TDS (if applicable): deducted half-up at the configured rate; the net must still reach the minimum', function (): void {
    $line = calculator(tds: 200)->line(100_001, 0, 0);     // 2 % of ₹1,000.01 = 2,000.02 paise → 2,000

    expect($line->tdsPaise)->toBe(2_000)
        ->and($line->netPaise)->toBe(98_001);

    $short = calculator(tds: 1000)->line(52_000, 0, 0);    // net 46,800 < 50,000 → rolls forward before TDS
    expect($short->payable)->toBeFalse()
        ->and($short->carriedForwardPaise)->toBe(52_000)
        ->and($short->tdsPaise)->toBe(0);
});

it('an empty line is recognised (nothing earned, clawed or carried)', function (): void {
    expect(calculator()->line(0, 0, 0)->isEmpty())->toBeTrue()
        ->and(calculator()->line(0, 1, 0)->isEmpty())->toBeFalse();
});
