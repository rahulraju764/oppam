<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Models\Broker;
use App\Services\Broker\BrokerCodeGenerator;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Broker> */
final class BrokerFactory extends Factory
{
    protected $model = Broker::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => fn (): string => app(BrokerCodeGenerator::class)->next(),
            'name' => fake()->name(),
            'bureau_name' => fake()->company().' Marriage Bureau',
            'phone' => '+9198'.fake()->unique()->numerify('########'),
            'status' => BrokerStatus::Active,
            'kyc_status' => BrokerKycStatus::Verified,
            'activated_at' => now(),
        ];
    }

    /** Just applied: no KYC yet (B.3). */
    public function applied(): self
    {
        return $this->state(['status' => BrokerStatus::Applied, 'kyc_status' => BrokerKycStatus::NotSubmitted, 'activated_at' => null]);
    }

    public function kycPending(): self
    {
        return $this->state(['status' => BrokerStatus::KycPending, 'kyc_status' => BrokerKycStatus::Pending, 'activated_at' => null]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => BrokerStatus::Suspended]);
    }
}
