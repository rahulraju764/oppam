<?php

declare(strict_types=1);

use App\ValueObjects\Money;

it('formats paise as rupees with Indian digit grouping', function (int $paise, string $expected): void {
    expect(Money::paise($paise)->format())->toBe($expected);
})->with([
    [0, '0'],
    [49900, '499'],
    [199900, '1,999'],
    [598800, '5,988'],
    [2398800, '23,988'],
    [10000000, '1,00,000'],
    [1234567850, '1,23,45,678.50'],
    [105, '1.05'],
]);

it('adds and multiplies without floats', function (): void {
    expect(Money::rupees(999)->multiply(12)->paise)->toBe(1198800)
        ->and(Money::paise(49900)->add(Money::paise(8982))->formatWithSymbol())->toBe('₹588.82');
});

it('refuses a negative amount', function (): void {
    Money::paise(-1);
})->throws(InvalidArgumentException::class);
