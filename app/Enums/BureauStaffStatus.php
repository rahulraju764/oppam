<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A bureau login's status (B.13 `broker_staff.status`). Only ACTIVE may use the portal — an
 * applicant Owner is INVITED until KYC approval (decisions 2026-10-23) and reaches the pre-KYC pages only.
 */
enum BureauStaffStatus: string
{
    case Invited = 'INVITED';
    case Active = 'ACTIVE';
    case Deactivated = 'DEACTIVATED';

    public function label(): string
    {
        return match ($this) {
            self::Invited => __('Invited'),
            self::Active => __('Active'),
            self::Deactivated => __('Deactivated'),
        };
    }

    /** @return array<string, string> value => label, for selects */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
