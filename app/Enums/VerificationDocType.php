<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The government IDs a member may verify with (PRD M09). Only the last four characters of the
 * number are ever stored (plus a keyed hash for duplicate checks — DocumentNumber); for Aadhaar
 * that is also the law (UIDAI: never keep the full number).
 */
enum VerificationDocType: string
{
    case Aadhaar = 'AADHAAR';
    case Passport = 'PASSPORT';
    case DrivingLicence = 'DRIVING_LICENCE';
    case VoterId = 'VOTER_ID';

    public function label(): string
    {
        return match ($this) {
            self::Aadhaar => __('Aadhaar card'),
            self::Passport => __('Passport'),
            self::DrivingLicence => __('Driving licence'),
            self::VoterId => __('Voter ID'),
        };
    }

    /** What the member is told the number looks like. */
    public function numberHint(): string
    {
        return match ($this) {
            self::Aadhaar => __('12 digits. We keep only the last 4.'),
            self::Passport => __('A letter and 7 digits, e.g. K1234567. We keep only the last 4.'),
            self::DrivingLicence => __('As printed, e.g. KL07 20110012345. We keep only the last 4.'),
            self::VoterId => __('3 letters and 7 digits (EPIC number). We keep only the last 4.'),
        };
    }

    /** The shape of the normalised number (upper case, no spaces or hyphens). */
    public function pattern(): string
    {
        return match ($this) {
            self::Aadhaar => '/^[2-9][0-9]{11}$/',
            self::Passport => '/^[A-Z][0-9]{7}$/',
            self::DrivingLicence => '/^[A-Z]{2}[0-9]{2}[A-Z0-9]{7,16}$/',
            self::VoterId => '/^[A-Z]{3}[0-9]{7}$/',
        };
    }

    /** The masked number shown to officers and kept after the purge, e.g. "XXXX XXXX 1234". */
    public function masked(string $last4): string
    {
        return match ($this) {
            self::Aadhaar => 'XXXX XXXX '.$last4,
            default => 'XXXX'.$last4,
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
