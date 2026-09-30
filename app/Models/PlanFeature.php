<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Entitlement;
use Database\Factories\PlanFeatureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One enforced entitlement of a plan (PRD §7.3). Quotas: limit_value (null = unlimited);
 * flags: is_enabled. Read only through EntitlementService (P0.6).
 *
 * @property string $id
 * @property string $plan_id
 * @property Entitlement $entitlement
 * @property int|null $limit_value
 * @property bool $is_enabled
 */
final class PlanFeature extends Model
{
    /** @use HasFactory<PlanFeatureFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['entitlement', 'limit_value', 'is_enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['entitlement' => Entitlement::class, 'limit_value' => 'integer', 'is_enabled' => 'boolean'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
