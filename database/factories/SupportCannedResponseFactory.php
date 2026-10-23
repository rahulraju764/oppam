<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SupportCannedResponse;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportCannedResponse> */
final class SupportCannedResponseFactory extends Factory
{
    protected $model = SupportCannedResponse::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'title' => fake()->words(3, true),
            'body' => 'Hello {first_name}, thank you for writing to us about ticket {ticket}. — {agent}',
        ];
    }
}
