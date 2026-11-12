<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored A09 number (P9.1a): a report metric's value for an IST day and a dimension
 * ('' = the total). Written only by TakeReportSnapshot.
 *
 * @property int $id
 * @property string $metric
 * @property CarbonImmutable $day
 * @property string $dimension
 * @property int $value
 * @property CarbonImmutable $computed_at
 */
final class ReportSnapshot extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'day' => 'immutable_date',
            'value' => 'integer',
            'computed_at' => 'immutable_datetime',
        ];
    }
}
