<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BureauRole;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileScope;
use App\Enums\UserRole;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerStaff> */
final class BrokerStaffFactory extends Factory
{
    protected $model = BrokerStaff::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'user_id' => User::factory()->state(['role' => UserRole::Broker]),
            'role' => BureauRole::Owner,
            'profile_scope' => ProfileScope::All,
            'status' => BureauStaffStatus::Active,
        ];
    }

    public function role(BureauRole $role): self
    {
        return $this->state(['role' => $role]);
    }
}
