<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the bureau's KYC stands (B.13 `brokers.kyc_status`). Only VERIFIED opens creation, import,
 * export and payouts (R-M13-10, R-A07-2).
 */
enum BrokerKycStatus: string
{
    case NotSubmitted = 'NOT_SUBMITTED';
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';
    case Revoked = 'REVOKED';

    public function label(): string
    {
        return match ($this) {
            self::NotSubmitted => __('Not submitted'),
            self::Pending => __('Pending review'),
            self::Verified => __('Verified'),
            self::Rejected => __('Rejected'),
            self::Revoked => __('Revoked'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
