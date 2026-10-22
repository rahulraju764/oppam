<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CouponRedemptionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A coupon used on a PAID order — written by ActivateSubscription, so an abandoned checkout never
 * uses up a member's (or the coupon's) limit.
 *
 * @property string $id
 * @property string $coupon_id
 * @property string $profile_id
 * @property string $order_id
 * @property int $discount_paise
 */
final class CouponRedemption extends Model
{
    /** @use HasFactory<CouponRedemptionFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['discount_paise' => 'integer'];
    }
}
