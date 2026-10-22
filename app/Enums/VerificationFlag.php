<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Automatic fraud signals on an ID request (PRD A05: "duplicate-document and name-mismatch flags",
 * "same doc hash on two profiles, banned-name match, rapid resubmission"). They only help the
 * officer — nothing is decided by a flag on its own.
 */
enum VerificationFlag: string
{
    case DuplicateDocument = 'DUPLICATE_DOCUMENT';
    case DuplicateNumber = 'DUPLICATE_NUMBER';
    case NameMismatch = 'NAME_MISMATCH';
    case BannedNameMatch = 'BANNED_NAME_MATCH';
    case RapidResubmission = 'RAPID_RESUBMISSION';

    public function label(): string
    {
        return match ($this) {
            self::DuplicateDocument => __('Same file on another profile'),
            self::DuplicateNumber => __('Same document number on another profile'),
            self::NameMismatch => __('Name differs from the profile'),
            self::BannedNameMatch => __('Matches a banned member'),
            self::RapidResubmission => __('Rapid resubmission'),
        };
    }

    public function isSerious(): bool
    {
        return in_array($this, [self::DuplicateDocument, self::DuplicateNumber, self::BannedNameMatch], true);
    }
}
