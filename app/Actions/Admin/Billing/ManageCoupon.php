<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Data\Billing\CouponData;
use App\Enums\CouponType;
use App\Enums\PlanCode;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Order;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 coupons (`billing.coupons.edit`, audited): percent / fixed, caps, per-member limit, validity,
 * plans, first-time-only, minimum order. An order keeps the discount it was created with. The code
 * is fixed once used; a used coupon is deactivated, never deleted (redemptions point at it).
 */
final class ManageCoupon
{
    use AuthorizesBilling;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function save(AdminUser $admin, ?Coupon $coupon, CouponData $data): Coupon
    {
        $this->authorizeFor($admin, 'billing.coupons.edit');
        $code = Coupon::normalise($data->code);
        $this->validate($coupon, $code, $data);

        return DB::transaction(function () use ($admin, $coupon, $code, $data): Coupon {
            $row = $coupon === null ? new Coupon : Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $before = $coupon === null ? [] : $this->snapshot($row);

            if ($row->exists && $row->redemptions_count > 0 && $row->code !== $code) {
                throw ValidationException::withMessages(['code' => __('The code of a used coupon can’t change.')]);
            }
            if ($row->exists && $data->maxRedemptions !== null && $data->maxRedemptions < $row->redemptions_count) {
                throw ValidationException::withMessages(['maxRedemptions' => __('This coupon has already been used :count times.', ['count' => $row->redemptions_count])]);
            }

            $row->forceFill([
                'code' => $code,
                'type' => $data->type,
                'value' => $data->value,
                'max_discount_paise' => $data->type === CouponType::Percent ? $data->maxDiscountPaise : null,
                'min_order_paise' => $data->minOrderPaise,
                'max_redemptions' => $data->maxRedemptions,
                'per_member_limit' => $data->perMemberLimit,
                'first_time_only' => $data->firstTimeOnly,
                'plan_codes' => $data->planCodes === [] ? null : $data->planCodes,
                'starts_at' => $data->startsAt?->utc(),
                'ends_at' => $data->endsAt?->utc(),
                'is_active' => $row->exists ? $row->is_active : true,
            ])->save();

            $this->audit->record($coupon === null ? 'billing.coupon_created' : 'billing.coupon_updated', $row, $before, $this->snapshot($row), actor: $admin, subjectLabel: $row->code);

            return $row;
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function setActive(AdminUser $admin, Coupon $coupon, bool $active): Coupon
    {
        $this->authorizeFor($admin, 'billing.coupons.edit');

        return DB::transaction(function () use ($admin, $coupon, $active): Coupon {
            $row = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            $before = ['active' => $row->is_active];
            $row->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'billing.coupon_activated' : 'billing.coupon_deactivated', $row, $before, ['active' => $active], actor: $admin, subjectLabel: $row->code);

            return $row;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws BillingRefused used, or on an order
     */
    public function delete(AdminUser $admin, Coupon $coupon): void
    {
        $this->authorizeFor($admin, 'billing.coupons.edit');

        DB::transaction(function () use ($admin, $coupon): void {
            $row = Coupon::query()->whereKey($coupon->id)->lockForUpdate()->firstOrFail();
            if ($row->redemptions_count > 0 || CouponRedemption::query()->where('coupon_id', $row->id)->exists() || Order::query()->where('coupon_id', $row->id)->exists()) {
                throw BillingRefused::couponUsed();
            }

            $this->audit->record('billing.coupon_deleted', $row, $this->snapshot($row), actor: $admin, subjectLabel: $row->code);
            $row->delete();
        });
    }

    /** @throws ValidationException */
    private function validate(?Coupon $coupon, string $code, CouponData $data): void
    {
        $errors = [];

        if (preg_match('/^[A-Z0-9]{3,30}$/', $code) !== 1) {
            $errors['code'] = __('Use 3 to 30 letters and numbers.');
        } elseif (Coupon::query()->where('code', $code)->when($coupon !== null, fn ($q) => $q->whereKeyNot($coupon?->id))->exists()) {
            $errors['code'] = __('Another coupon already uses this code.');
        }

        if ($data->type === CouponType::Percent && ($data->value < 1 || $data->value > 100)) {
            $errors['value'] = __('Enter a percentage from 1 to 100.');
        }
        if ($data->type === CouponType::Fixed && ($data->value < 100 || $data->value > 20_000_000)) {
            $errors['value'] = __('Enter an amount from ₹1 to ₹2,00,000.');
        }
        foreach (['maxDiscountPaise' => $data->maxDiscountPaise, 'minOrderPaise' => $data->minOrderPaise] as $field => $paise) {
            if ($paise !== null && ($paise < 0 || $paise > 20_000_000)) {
                $errors[$field] = __('Enter an amount up to ₹2,00,000.');
            }
        }
        if ($data->maxRedemptions !== null && $data->maxRedemptions < 1) {
            $errors['maxRedemptions'] = __('Enter at least 1, or leave it empty for no limit.');
        }
        if ($data->perMemberLimit < 1 || $data->perMemberLimit > 100) {
            $errors['perMemberLimit'] = __('Enter a limit from 1 to 100.');
        }
        if ($data->startsAt !== null && $data->endsAt !== null && $data->endsAt->lte($data->startsAt)) {
            $errors['endsAt'] = __('The end must be after the start.');
        }

        $paid = array_values(array_filter(array_map(fn (PlanCode $p): string => $p->value, PlanCode::cases()), fn (string $p): bool => $p !== PlanCode::Free->value));
        if (array_diff($data->planCodes ?? [], $paid) !== []) {
            $errors['planCodes'] = __('Choose paid plans only.');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(Coupon $coupon): array
    {
        return [
            'code' => $coupon->code, 'type' => $coupon->type->value, 'value' => $coupon->value, 'max_discount_paise' => $coupon->max_discount_paise,
            'min_order_paise' => $coupon->min_order_paise, 'max_redemptions' => $coupon->max_redemptions, 'per_member_limit' => $coupon->per_member_limit,
            'first_time_only' => $coupon->first_time_only, 'plan_codes' => $coupon->plan_codes,
            'starts_at' => $coupon->starts_at?->toIso8601String(), 'ends_at' => $coupon->ends_at?->toIso8601String(),
        ];
    }
}
