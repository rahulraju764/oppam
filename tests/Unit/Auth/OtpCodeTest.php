<?php

declare(strict_types=1);

use App\Domain\Auth\OtpCode;

/*
| P1.1 — R-M01-2: 6 digits, stored only as an HMAC bound to its challenge.
*/

it('generates 6-digit numeric codes, zero-padded', function (): void {
    foreach (range(1, 200) as $_) {
        expect(OtpCode::generate())->toMatch('/^\d{6}$/');
    }
});

it('matches only the same code for the same challenge and key', function (): void {
    $hash = OtpCode::hash('challenge-a', '123456', 'key');

    expect($hash)->not->toContain('123456')
        ->and(OtpCode::matches('challenge-a', '123456', $hash, 'key'))->toBeTrue()
        ->and(OtpCode::matches('challenge-a', '123457', $hash, 'key'))->toBeFalse()
        ->and(OtpCode::matches('challenge-b', '123456', $hash, 'key'))->toBeFalse()   // no replay on another challenge
        ->and(OtpCode::matches('challenge-a', '123456', $hash, 'other-key'))->toBeFalse();
});

it('treats anything but exactly 6 digits as malformed', function (string $code, bool $ok): void {
    expect(OtpCode::isWellFormed($code))->toBe($ok);
})->with([
    ['123456', true],
    ['012345', true],
    ['12345', false],
    ['1234567', false],
    ['12 456', false],
    ['abcdef', false],
    ['', false],
]);
