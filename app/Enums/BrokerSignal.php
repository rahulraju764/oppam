<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A07 fraud & quality signals (§11A B.12 signal table). The three money signals put the bureau's
 * payouts on hold automatically (owner 2026-10-30) until an admin releases them; the others are
 * flags for review. Two are also told to the Owner (B.12 "Owner and admin notified").
 */
enum BrokerSignal: string
{
    case SharedSignups = 'SHARED_SIGNUPS';             // many referrals from one IP / network / device
    case IncompleteReferrals = 'INCOMPLETE_REFERRALS'; // referred accounts that never complete a profile
    case PayThenRefund = 'PAY_THEN_REFUND';            // referred members who pay, then refund at once
    case SelfReferral = 'SELF_REFERRAL';               // a referral signed up on a bureau login's device / IP
    case ReferralSpike = 'REFERRAL_SPIKE';             // far above the bureau's own history
    case ExportPattern = 'EXPORT_PATTERN';             // unusual export volume / repeats
    case HighRejection = 'HIGH_REJECTION';             // managed / imported profiles often rejected at A04
    case ConsentDisputes = 'CONSENT_DISPUTES';         // "I never agreed" complaints (R-A07-10)
    case ImportMobilePattern = 'IMPORT_MOBILE_PATTERN'; // many imported mobiles sharing a pattern
    case ImportPhotoMatch = 'IMPORT_PHOTO_MATCH';      // imported photos matching other profiles (pHash)
    case StaffDevices = 'STAFF_DEVICES';               // one staff login on many devices / IPs

    public function label(): string
    {
        return match ($this) {
            self::SharedSignups => __('Many sign-ups from one device or network'),
            self::IncompleteReferrals => __('Referred members rarely complete a profile'),
            self::PayThenRefund => __('Referred members pay, then refund'),
            self::SelfReferral => __('Referrals from the bureau’s own devices'),
            self::ReferralSpike => __('Referral spike'),
            self::ExportPattern => __('Unusual exports'),
            self::HighRejection => __('Many profiles rejected at review'),
            self::ConsentDisputes => __('Consent disputes'),
            self::ImportMobilePattern => __('Imported mobiles share a pattern'),
            self::ImportPhotoMatch => __('Imported photos match other profiles'),
            self::StaffDevices => __('A staff login on many devices'),
        };
    }

    /** "Hold commission" / "review before payout" (B.12): payouts are held automatically. */
    public function holdsPayouts(): bool
    {
        return in_array($this, [self::SharedSignups, self::SelfReferral, self::ReferralSpike], true);
    }

    /** B.12 "Owner and admin notified". */
    public function tellsOwner(): bool
    {
        return in_array($this, [self::StaffDevices, self::ExportPattern], true);
    }
}
