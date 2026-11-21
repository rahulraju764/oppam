<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Models\WebhookReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An accepted Razorpay delivery.
 *
 * @extends Factory<WebhookReceipt>
 */
final class WebhookReceiptFactory extends Factory
{
    protected $model = WebhookReceipt::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'provider' => WebhookProvider::Razorpay,
            'outcome' => WebhookOutcome::Accepted,
            'http_status' => 200,
            'duration_ms' => 40,
            'received_at' => now(),
        ];
    }

    public function rejectedSignature(): self
    {
        return $this->state(['outcome' => WebhookOutcome::RejectedSignature, 'http_status' => 400]);
    }
}
