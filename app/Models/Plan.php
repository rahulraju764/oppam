<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanCode;
use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A membership plan (PRD §7.3, M10, A06). Prices per duration in plan_prices (paise);
 * enforced limits in plan_features; display_features is the marketing list on the card.
 * Edited only in A06 (P5.3); never deleted once referenced — deactivated.
 *
 * @property string $id
 * @property PlanCode $code
 * @property string $name
 * @property string|null $badge
 * @property bool $is_featured
 * @property bool $is_purchasable
 * @property bool $is_active
 * @property list<string> $display_features
 * @property int $sort_order
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['name', 'badge', 'is_featured', 'is_purchasable', 'is_active', 'display_features', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'code' => PlanCode::class,
            'is_featured' => 'boolean',
            'is_purchasable' => 'boolean',
            'is_active' => 'boolean',
            'display_features' => 'array',
            'sort_order' => 'integer',
        ];
    }

    /** @param Builder<self> $query */
    public function scopePurchasable(Builder $query): void
    {
        $query->where('is_active', true)->where('is_purchasable', true)->orderBy('sort_order');
    }

    /** @return HasMany<PlanPrice, $this> */
    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }
}
