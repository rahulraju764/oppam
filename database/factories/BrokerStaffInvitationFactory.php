<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BureauRole;
use App\Enums\ProfileScope;
use App\Models\Broker;
use App\Models\BrokerStaffInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BrokerStaffInvitation> */
final class BrokerStaffInvitationFactory extends Factory
{
    protected $model = BrokerStaffInvitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'phone' => '+9197'.fake()->unique()->numerify('########'),
            'name' => fake()->name(),
            'role' => BureauRole::DataEntry,
            'profile_scope' => ProfileScope::All,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
        ];
    }
}
