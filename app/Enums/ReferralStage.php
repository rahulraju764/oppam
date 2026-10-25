<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a bureau sees about a referred member (B.5 privacy: "status (joined / profile live /
 * converted)" — plus refunded once a conversion is voided). Derived from the ledger row and the
 * profile's first publish; never stored.
 */
enum ReferralStage: string
{
    case Joined = 'joined';
    case Live = 'live';
    case Converted = 'converted';
    case Refunded = 'refunded';

    public static function of(ReferralStatus $status, bool $published): self
    {
        return match ($status) {
            ReferralStatus::Earned, ReferralStatus::Paid => self::Converted,
            ReferralStatus::Void => self::Refunded,
            ReferralStatus::Pending => $published ? self::Live : self::Joined,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Joined => __('Joined'),
            self::Live => __('Profile live'),
            self::Converted => __('Converted'),
            self::Refunded => __('Refunded'),
        };
    }

    /** <x-ui.badge> variant. */
    public function badge(): string
    {
        return match ($this) {
            self::Joined => 'muted',
            self::Live => 'verified',
            self::Converted => 'success',
            self::Refunded => 'warning',
        };
    }

    /** @return array<string, string> value => label, for the filter */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
