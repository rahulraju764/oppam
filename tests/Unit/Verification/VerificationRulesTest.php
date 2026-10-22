<?php

declare(strict_types=1);

use App\Domain\Verification\DocumentNumber;
use App\Domain\Verification\NameMatcher;
use App\Enums\VerificationDocType;

/*
| P6.1 — M09 masked numbers (Aadhaar: 12 digits + Verhoeff check digit; only the last 4 kept) and
| the A05 name-mismatch rule with Malayali naming variants. Framework-free.
*/

it('M09: parses and masks document numbers in their printed formats', function (VerificationDocType $type, string $raw, ?string $masked): void {
    expect(DocumentNumber::parse($type, $raw)?->masked())->toBe($masked);
})->with([
    'aadhaar with spaces' => [VerificationDocType::Aadhaar, '2345 6789 0124', 'XXXX XXXX 0124'],
    'aadhaar with hyphens' => [VerificationDocType::Aadhaar, '2345-6789-0124', 'XXXX XXXX 0124'],
    'aadhaar typo (check digit)' => [VerificationDocType::Aadhaar, '2345 6789 0125', null],
    'aadhaar swapped digits' => [VerificationDocType::Aadhaar, '3245 6789 0124', null],
    'aadhaar 11 digits' => [VerificationDocType::Aadhaar, '2345 6789 012', null],
    'aadhaar leading 0' => [VerificationDocType::Aadhaar, '0345 6789 0124', null],
    'passport' => [VerificationDocType::Passport, 'k1234567', 'XXXX4567'],
    'passport digits only' => [VerificationDocType::Passport, '12345678', null],
    'driving licence' => [VerificationDocType::DrivingLicence, 'KL-07 2011 0012345', 'XXXX2345'],
    'driving licence too short' => [VerificationDocType::DrivingLicence, 'KL07123', null],
    'voter id' => [VerificationDocType::VoterId, 'ABC1234567', 'XXXX4567'],
    'voter id wrong shape' => [VerificationDocType::VoterId, 'AB12345678', null],
]);

it('keeps only the last 4 characters', function (): void {
    expect(DocumentNumber::parse(VerificationDocType::Aadhaar, '987654321096')?->last4())->toBe('1096');
});

it('A05 name check: Kerala naming variants are not a mismatch, a different person is', function (string $first, string $document, bool $matches): void {
    expect((new NameMatcher)->matches($first, $document))->toBe($matches);
})->with([
    'same' => ['Anjali', 'Anjali Nair', true],
    'initial after' => ['Anjali', 'ANJALI K.', true],
    'initial before' => ['Anjali', 'K. Anjali', true],
    'father’s name as surname' => ['Anjali', 'Anjali Krishnan', true],
    'house-name initials' => ['Thomas', 'P.V. Thomas', true],
    'sree / sri' => ['Sreelakshmi', 'Srilakshmi M', true],
    'ee / i, th / t' => ['Shibu', 'Sibu Thomas', true],
    'double letters' => ['Anna', 'Ana Joseph', true],
    'two-word first name, other order' => ['Mary Ann', 'Ann Mary Joseph', true],
    'different person' => ['Anjali', 'Lakshmi Pillai', false],
    'only an initial on the ID' => ['Anjali', 'A. Nair', false],
    'empty' => ['Anjali', '', false],
]);

it('normalises names for the banned-name match (order, initials, spelling)', function (): void {
    $names = new NameMatcher;

    expect($names->key('Divya K. Menon'))->toBe($names->key('MENON DIVYA'))
        ->and($names->key('Sreeja Thomas'))->toBe($names->key('Srija Tomas'))
        ->and($names->key('Divya Menon'))->not->toBe($names->key('Divya Nair'));
});
