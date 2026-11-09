<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OutboundMessage> */
final class OutboundMessageFactory extends Factory
{
    protected $model = OutboundMessage::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'channel' => CommsChannel::Email,
            'recipient_masked' => 'a•••@example.com',
            'event' => 'profile_approved',
            'status' => OutboundStatus::Sent,
            'provider_id' => Str::lower(Str::random(20)),
            'sent_at' => now(),
        ];
    }
}
