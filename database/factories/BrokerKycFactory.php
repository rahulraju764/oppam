<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\KycSubmissionStatus;
use App\Models\Broker;
use App\Models\BrokerKyc;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerKyc> */
final class BrokerKycFactory extends Factory
{
    protected $model = BrokerKyc::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'pan_number_encrypted' => 'ABCDE1234F',
            'pan_last4' => '234F',
            'bank_account_encrypted' => '123456789012',
            'bank_last4' => '9012',
            'ifsc' => 'SBIN0001234',
            'account_name' => fake()->name(),
            'status' => KycSubmissionStatus::Pending,
        ];
    }
}
