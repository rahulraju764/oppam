<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * One KYC submission (B.13 `broker_kyc.status`).
 */
enum KycSubmissionStatus: string
{
    case Pending = 'PENDING';
    case Verified = 'VERIFIED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Pending => __('Pending review'),
            self::Verified => __('Verified'),
            self::Rejected => __('Rejected'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
