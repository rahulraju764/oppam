<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ClientNoteType;
use App\Models\Broker;
use App\Models\BrokerClientNote;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerClientNote> */
final class BrokerClientNoteFactory extends Factory
{
    protected $model = BrokerClientNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'profile_id' => Profile::factory(),
            'broker_id' => Broker::factory(),
            'author_user_id' => User::factory(),
            'type' => ClientNoteType::Note,
            'body' => fake()->sentence(),
        ];
    }
}
