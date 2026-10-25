<?php

declare(strict_types=1);

namespace App\Data\Broker;

/**
 * What a visitor may learn about a referral code (B.5 "validate?code= returns {valid, name,
 * district} only"): an ACTIVE bureau's display name and district — never its phone, email, PAN or
 * owner. Unknown and inactive codes look the same (valid = false).
 */
final readonly class ReferralCodeHintData
{
    public function __construct(
        public bool $valid,
        public ?string $name = null,
        public ?string $district = null,
    ) {}

    /** @return array{valid: bool, name: string|null, district: string|null} */
    public function toArray(): array
    {
        return ['valid' => $this->valid, 'name' => $this->name, 'district' => $this->district];
    }
}
