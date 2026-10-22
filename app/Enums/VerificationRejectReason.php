<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why an officer rejected an ID (A05 "reasons → member guidance"). Each maps to what the member
 * reads on /verify and in the notification; the officer's own note follows it.
 */
enum VerificationRejectReason: string
{
    case Unreadable = 'UNREADABLE';
    case CutOff = 'CUT_OFF';
    case Expired = 'EXPIRED';
    case NameMismatch = 'NAME_MISMATCH';
    case DobMismatch = 'DOB_MISMATCH';
    case NumberMismatch = 'NUMBER_MISMATCH';
    case Unsupported = 'UNSUPPORTED';
    case NotOwnDocument = 'NOT_OWN_DOCUMENT';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Unreadable => __('Blurred or unreadable'),
            self::CutOff => __('Part of the document is cut off'),
            self::Expired => __('Document has expired'),
            self::NameMismatch => __('Name does not match the profile'),
            self::DobMismatch => __('Date of birth does not match the profile'),
            self::NumberMismatch => __('Number does not match the document'),
            self::Unsupported => __('Not a supported document'),
            self::NotOwnDocument => __('Not the member’s own document / suspected fraud'),
            self::Other => __('Other'),
        };
    }

    /** What the member reads (the officer's note follows). */
    public function memberMessage(): string
    {
        return match ($this) {
            self::Unreadable => __('We couldn’t read your document. Please upload a sharp, well-lit photo or scan where every word is readable.'),
            self::CutOff => __('Part of your document was cut off. Please upload a photo that shows the whole card or page, with all four corners.'),
            self::Expired => __('This document has expired. Please upload a valid one.'),
            self::NameMismatch => __('The name on your document doesn’t match the name on your profile. Initials and house names are fine — please check the spelling, or contact us if your name changed.'),
            self::DobMismatch => __('The date of birth on your document doesn’t match your profile. Please contact us so we can correct it.'),
            self::NumberMismatch => __('The number you typed doesn’t match the document. Please type it exactly as printed.'),
            self::Unsupported => __('Please upload an Aadhaar card, passport, driving licence or voter ID.'),
            self::NotOwnDocument => __('We couldn’t confirm this document is yours. Please contact our support team.'),
            self::Other => __('We couldn’t verify your document this time.'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
