<?php

declare(strict_types=1);

namespace App\Models;

use App\ValueObjects\Money;
use Database\Factories\PlanPriceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The pre-GST price of a plan for a duration, in paise. Checkout reads the amount from here by
 * plan key — never from the browser (CLAUDE.md "Money").
 *
 * @property string $id
 * @property string $plan_id
 * @property int $duration_months
 * @property int $price_paise
 * @property bool $is_active
 */
final class PlanPrice extends Model
{
    /** @use HasFactory<PlanPriceFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = ['duration_months', 'price_paise', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['duration_months' => 'integer', 'price_paise' => 'integer', 'is_active' => 'boolean'];
    }

    public function price(): Money
    {
        return Money::paise($this->price_paise);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
