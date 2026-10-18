<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ReportReason;
use App\Enums\ReportSource;
use App\Enums\ReportStatus;
use App\Models\Profile;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Report> */
final class ReportFactory extends Factory
{
    protected $model = Report::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'reporter_profile_id' => Profile::factory(),
            'reported_profile_id' => Profile::factory(),
            'source' => ReportSource::Chat,
            'reason' => ReportReason::AbusiveMessages,
            'status' => ReportStatus::Open,
        ];
    }
}
