<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TicketAuthor;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SupportTicketMessage> */
final class SupportTicketMessageFactory extends Factory
{
    protected $model = SupportTicketMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'support_ticket_id' => SupportTicket::factory(),
            'author' => TicketAuthor::Member,
            'body' => fake()->paragraph(),
            'is_internal' => false,
        ];
    }

    public function internal(): self
    {
        return $this->state(['author' => TicketAuthor::Admin, 'is_internal' => true]);
    }
}
