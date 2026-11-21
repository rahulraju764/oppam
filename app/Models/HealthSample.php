<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HealthMetric;
use Database\Factories\HealthSampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A12 system health: one per-minute measurement (Reverb connections, broadcast lag). A null value
 * means it couldn't be measured. Internal only (auto-increment id, never in a URL).
 *
 * @property int $id
 * @property HealthMetric $metric
 * @property int|null $value
 * @property Carbon $sampled_at
 */
final class HealthSample extends Model
{
    /** @use HasFactory<HealthSampleFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => HealthMetric::class,
            'value' => 'integer',
            'sampled_at' => 'datetime',
        ];
    }

    public static function record(HealthMetric $metric, ?int $value): self
    {
        $sample = new self;
        $sample->forceFill(['metric' => $metric, 'value' => $value, 'sampled_at' => now()])->save();

        return $sample;
    }
}
