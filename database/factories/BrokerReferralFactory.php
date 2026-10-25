<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use App\Models\Broker;
use App\Models\BrokerReferral;
use App\Models\Order;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerReferral> */
final class BrokerReferralFactory extends Factory
{
    protected $model = BrokerReferral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'profile_id' => Profile::factory(),
            'source' => ReferralSource::Referral,
            'status' => ReferralStatus::Pending,
        ];
    }

    /** Earned from $order at the default 10 % (B.10). */
    public function earned(Order $order, int $bps = 1000): static
    {
        return $this->state(fn (): array => [
            'profile_id' => $order->profile_id,
            'order_id' => $order->id,
            'base_paise' => $order->taxable_paise,
            'commission_bps' => $bps,
            'commission_paise' => intdiv($order->taxable_paise * $bps + 5_000, 10_000),
            'status' => ReferralStatus::Earned,
            'earned_at' => now(),
        ]);
    }

    public function managed(): static
    {
        return $this->state(['source' => ReferralSource::Managed]);
    }
}
