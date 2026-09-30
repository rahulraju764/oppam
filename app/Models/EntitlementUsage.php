<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Entitlement;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A usage counter for one profile × entitlement × period (PRD §7.3). Written only by
 * EntitlementService with an atomic conditional increment; this model is for reads/reports.
 *
 * @property string $id
 * @property string $profile_id
 * @property Entitlement $entitlement
 * @property Carbon $period_start
 * @property Carbon|null $period_end
 * @property int $used
 */
final class EntitlementUsage extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'entitlement' => Entitlement::class,
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'used' => 'integer',
        ];
    }
}
