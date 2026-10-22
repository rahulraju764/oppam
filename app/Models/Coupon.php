<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CouponType;
use Database\Factories\CouponFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A discount code (A06 "coupons"): percent (with an optional cap) or a fixed amount off the pre-GST
 * plan price, with validity dates, plan list, minimum order, total and per-member limits and
 * "first paid order only". Checked by CouponEligibility; created and edited in A06 (P5.3).
 *
 * @property string $id
 * @property string $code
 * @property CouponType $type
 * @property int $value
 * @property int|null $max_discount_paise
 * @property int|null $min_order_paise
 * @property int|null $max_redemptions
 * @property int $per_member_limit
 * @property bool $first_time_only
 * @property list<string>|null $plan_codes
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property bool $is_active
 * @property int $redemptions_count
 */
final class Coupon extends Model
{
    /** @use HasFactory<CouponFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'value' => 'integer',
            'max_discount_paise' => 'integer',
            'min_order_paise' => 'integer',
            'max_redemptions' => 'integer',
            'per_member_limit' => 'integer',
            'first_time_only' => 'boolean',
            'plan_codes' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'redemptions_count' => 'integer',
        ];
    }

    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }
}
