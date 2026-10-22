<?php

declare(strict_types=1);

namespace App\Data\Billing;

use App\Enums\PlanCode;
use App\Enums\SubscriptionSource;
use Carbon\CarbonImmutable;

/** The member's plan as /billing shows it (MembershipSummary). */
final readonly class MembershipSummaryData
{
    /**
     * @param  list<array{plan: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $queued  bought, not started yet
     * @param  list<UsageMeterData>  $meters
     * @param  list<array{label: string, included: bool}>  $features  the plan's on / off entitlements
     */
    public function __construct(
        public PlanCode $plan,
        public string $planName,
        public ?SubscriptionSource $source,
        public ?CarbonImmutable $endsAt,
        public array $queued,
        public array $meters,
        public array $features,
    ) {}

    public function isFree(): bool
    {
        return $this->plan === PlanCode::Free;
    }
}
