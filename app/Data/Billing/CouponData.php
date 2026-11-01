<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\CouponType;
use Carbon\CarbonImmutable;

/** An A06 coupon as the form sends it, already typed. `value`: percent, or paise for a fixed coupon. */
final readonly class CouponData
{
    /** @param  list<string>|null  $planCodes  null = every paid plan */
    public function __construct(
        public string $code,
        public CouponType $type,
        public int $value,
        public ?int $maxDiscountPaise,
        public ?int $minOrderPaise,
        public ?int $maxRedemptions,
        public int $perMemberLimit,
        public bool $firstTimeOnly,
        public ?array $planCodes,
        public ?CarbonImmutable $startsAt,
        public ?CarbonImmutable $endsAt,
    ) {}
}
