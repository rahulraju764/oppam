<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bureau's lifecycle (PRD §11A B.3). APPLIED → KYC_PENDING (KYC sent) → ACTIVE; REJECTED,
 * SUSPENDED (→ ACTIVE again) and DEACTIVATED (history kept).
 */
enum BrokerStatus: string
{
    case Applied = 'APPLIED';
    case KycPending = 'KYC_PENDING';
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Deactivated = 'DEACTIVATED';
    case Rejected = 'REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::Applied => __('Applied'),
            self::KycPending => __('KYC pending'),
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
            self::Deactivated => __('Deactivated'),
            self::Rejected => __('Rejected'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
