<?php

declare(strict_types=1);

use App\ValueObjects\PhoneNumber;

/*
| P1.1 — the member login id (PRD §7.2, R-M01-1): E.164, strict India rule, NRI lengths.
*/

it('normalises what people type into E.164', function (string $country, string $typed, string $e164): void {
    expect(PhoneNumber::fromParts($country, $typed)->e164())->toBe($e164);
})->with([
    'plain' => ['91', '9876543210', '+919876543210'],
    'spaces and dashes' => ['91', '98765-43 210', '+919876543210'],
    'trunk zero' => ['91', '09876543210', '+919876543210'],
    'country code repeated' => ['91', '91 98765 43210', '+919876543210'],
    'plus in select' => ['+91', '9876543210', '+919876543210'],
    'UAE' => ['971', '501234567', '+971501234567'],
    'Saudi' => ['966', '512345678', '+966512345678'],
    'UK' => ['44', '07700900123', '+447700900123'],
    'USA' => ['1', '2025550143', '+12025550143'],
]);

it('rejects numbers that are not mobiles for the country', function (string $country, string $typed): void {
    expect(fn () => PhoneNumber::fromParts($country, $typed))->toThrow(InvalidArgumentException::class);
})->with([
    'India landline-like start' => ['91', '4842345678'],
    'India too short' => ['91', '987654321'],
    'India too long' => ['91', '98765432101'],
    'letters' => ['91', 'abcdefghij'],
    'empty' => ['91', ''],
    'UAE wrong length' => ['971', '50123456'],
    'unsupported country' => ['92', '3001234567'],
]);

it('round-trips through E.164', function (): void {
    $phone = PhoneNumber::fromE164('+971501234567');

    expect($phone->countryCode)->toBe('971')
        ->and($phone->nationalNumber)->toBe('501234567')
        ->and($phone->equals(PhoneNumber::fromParts('971', '0501234567')))->toBeTrue();
});

it('parses the single login box: India by default, + for other countries, null otherwise', function (): void {
    expect(PhoneNumber::tryParse('98765 43210')?->e164())->toBe('+919876543210')
        ->and(PhoneNumber::tryParse('+971 50 123 4567')?->e164())->toBe('+971501234567')
        ->and(PhoneNumber::tryParse('anjali@example.com'))->toBeNull()
        ->and(PhoneNumber::tryParse('OPM10001'))->toBeNull()
        ->and(PhoneNumber::tryFromParts('91', '123'))->toBeNull();
});

it('masks the number for display and logs', function (): void {
    expect(PhoneNumber::fromParts('91', '9876543210')->masked())->toBe('+91 98•••••210');
});

it('lists the supported calling codes as strings for validation', function (): void {
    expect(PhoneNumber::countryCodes())->toBe(['91', '971', '966', '44', '1']);
});
