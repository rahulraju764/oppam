<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayoutRunStatus;
use Database\Factories\BrokerPayoutRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A payout run for a period (A07; P7.8). Above the threshold a second approver signs (R-A07-4). Nothing is mass-assignable.
 *
 * @property string $id
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property PayoutRunStatus $status
 * @property string|null $created_by_admin_id
 * @property string|null $approved_by_admin_id
 * @property string|null $second_approved_by_admin_id
 * @property Carbon|null $approved_at
 * @property Carbon|null $paid_at
 */
final class BrokerPayoutRun extends Model
{
    /** @use HasFactory<BrokerPayoutRunFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'status' => PayoutRunStatus::class,
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }
}
