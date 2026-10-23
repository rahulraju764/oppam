<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\BrokerExportType;
use App\Models\Broker;
use App\Models\BrokerProfileExport;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BrokerProfileExport> */
final class BrokerProfileExportFactory extends Factory
{
    protected $model = BrokerProfileExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'broker_id' => Broker::factory(),
            'user_id' => User::factory(),
            'profile_id' => Profile::factory(),
            'export_type' => BrokerExportType::BiodataPdf,
            'allowed' => true,
        ];
    }
}
