<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicket> */
final class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'TKT'.fake()->unique()->numberBetween(90000, 99999),
            'source' => TicketSource::ContactForm,
            'category' => TicketCategory::Account,
            'status' => TicketStatus::Open,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'subject' => fake()->sentence(4),
            'first_response_due_at' => now()->addHours(48),
            'last_activity_at' => now(),
        ];
    }

    public function forMember(User $member): self
    {
        return $this->state(['user_id' => $member->id, 'source' => TicketSource::InApp, 'email' => $member->email]);
    }

    public function status(TicketStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
