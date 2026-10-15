<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Metric;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One KPI value for one IST day (PRD A02 `metric_snapshots`). Written only by
 * App\Actions\Admin\Metrics\TakeMetricSnapshot.
 *
 * @property string $id
 * @property Metric $metric
 * @property \Carbon\CarbonImmutable $day
 * @property int $value
 * @property \Carbon\CarbonImmutable $computed_at
 */
final class MetricSnapshot extends Model
{
    use HasUlids;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'metric' => Metric::class,
            'day' => 'immutable_date',
            'value' => 'integer',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
