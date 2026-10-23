<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\InvestigationStatus;
use App\Enums\InvestigationType;
use App\Models\BillingInvestigation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillingInvestigation> */
final class BillingInvestigationFactory extends Factory
{
    protected $model = BillingInvestigation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $payment = 'pay_'.fake()->unique()->bothify('??????????????');

        return [
            'type' => InvestigationType::UnknownOrder,
            'status' => InvestigationStatus::Open,
            'fingerprint' => InvestigationType::UnknownOrder->value.':'.$payment,
            'order_id' => null,
            'refund_id' => null,
            'gateway_payment_id' => $payment,
            'details' => ['amount_paise' => 117_882, 'gateway_order_id' => 'order_unknown'],
        ];
    }
}
