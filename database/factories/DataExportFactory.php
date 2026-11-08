<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DataExportStatus;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<DataExport> */
final class DataExportFactory extends Factory
{
    protected $model = DataExport::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'DEX'.Str::upper(Str::random(10)),
            'user_id' => User::factory(),
            'status' => DataExportStatus::Queued,
        ];
    }

    public function ready(string $path = 'exports/test.zip'): static
    {
        return $this->state(['status' => DataExportStatus::Ready, 'file_path' => $path, 'file_size_bytes' => 10,
            'completed_at' => now(), 'expires_at' => now()->addDay()]);
    }
}
