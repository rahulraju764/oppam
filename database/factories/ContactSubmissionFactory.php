<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ContactStatus;
use App\Enums\TicketCategory;
use App\Models\ContactSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ContactSubmission> */
final class ContactSubmissionFactory extends Factory
{
    protected $model = ContactSubmission::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'CNT'.Str::upper(Str::random(8)),
            'user_id' => null,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'category' => TicketCategory::Other,
            'message' => 'I would like to know more about your branches.',
            'status' => ContactStatus::New,
            'ip_hash' => hash('sha256', 'test'),
        ];
    }

    public function spam(): static
    {
        return $this->state(['status' => ContactStatus::Spam, 'spam_reason' => 'links']);
    }
}
