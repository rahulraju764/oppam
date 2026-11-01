<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\ManageCoupon;
use App\Data\Billing\CouponData;
use App\Enums\CouponType;
use App\Enums\PlanCode;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Coupon;
use App\ValueObjects\Money;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/** A06 coupons: list, create and edit in one dialog, deactivate, delete an unused one. */
#[Layout('layouts::admin', ['title' => 'Coupons'])]
final class CouponsIndex extends Component
{
    use WithPagination;

    #[Locked]
    public ?string $editing = null;

    public string $code = '';

    public string $type = 'PERCENT';

    public string $value = '';

    public string $maxDiscount = '';

    public string $minOrder = '';

    public string $maxRedemptions = '';

    public string $perMemberLimit = '1';

    public bool $firstTimeOnly = false;

    /** @var array<int, string> */
    public array $planCodes = [];

    public string $startsAt = '';

    public string $endsAt = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
    }

    public function create(): void
    {
        $this->authorize('billing.coupons.edit');
        $this->resetForm();
        $this->dispatch('open-modal', name: 'coupon-form');
    }

    public function edit(string $code): void
    {
        $this->authorize('billing.coupons.edit');
        $coupon = $this->find($code);
        $this->resetForm();
        $timezone = (string) config('oppam.display_timezone');

        $this->editing = $coupon->code;
        $this->code = $coupon->code;
        $this->type = $coupon->type->value;
        $this->value = $coupon->type === CouponType::Percent ? (string) $coupon->value : Money::paise($coupon->value)->format();
        $this->maxDiscount = $coupon->max_discount_paise !== null ? (string) ($coupon->max_discount_paise / 100) : '';
        $this->minOrder = $coupon->min_order_paise !== null ? (string) ($coupon->min_order_paise / 100) : '';
        $this->maxRedemptions = (string) $coupon->max_redemptions;
        $this->perMemberLimit = (string) $coupon->per_member_limit;
        $this->firstTimeOnly = $coupon->first_time_only;
        $this->planCodes = $coupon->plan_codes ?? [];
        $this->startsAt = $coupon->starts_at?->timezone($timezone)->format('Y-m-d\TH:i') ?? '';
        $this->endsAt = $coupon->ends_at?->timezone($timezone)->format('Y-m-d\TH:i') ?? '';

        $this->dispatch('open-modal', name: 'coupon-form');
    }

    public function save(ManageCoupon $manage): void
    {
        $this->resetErrorBag();
        $coupon = $this->editing !== null ? $this->find($this->editing) : null;
        $manage->save($this->admin(), $coupon, $this->data());

        $this->dispatch('close-modal', name: 'coupon-form');
        $this->dispatch('toast', type: 'success', title: __('Coupon saved.'));
        $this->resetForm();
    }

    public function toggle(ManageCoupon $manage, string $code): void
    {
        $coupon = $this->find($code);
        $manage->setActive($this->admin(), $coupon, ! $coupon->is_active);
        $this->dispatch('toast', type: 'success', title: $coupon->is_active ? __('Coupon deactivated.') : __('Coupon activated.'));
    }

    public function delete(ManageCoupon $manage, string $code): void
    {
        try {
            $manage->delete($this->admin(), $this->find($code));
        } catch (BillingRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('Coupon deleted.'));
    }

    public function render(): View
    {
        return view('livewire.admin.billing.coupons-index', [
            'coupons' => Coupon::query()->latest()->orderByDesc('id')->paginate(25),
            'types' => CouponType::cases(),
            'plans' => array_values(array_filter(PlanCode::cases(), fn (PlanCode $p): bool => $p !== PlanCode::Free)),
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /** @throws ValidationException */
    private function data(): CouponData
    {
        $type = CouponType::tryFrom($this->type) ?? throw ValidationException::withMessages(['type' => __('Choose a type.')]);
        $value = $type === CouponType::Percent
            ? (preg_match('/^\d{1,3}$/', trim($this->value)) === 1 ? (int) $this->value : null)
            : Money::parseRupees($this->value);

        return new CouponData(
            code: $this->code,
            type: $type,
            value: $value ?? throw ValidationException::withMessages(['value' => __('Enter a number.')]),
            maxDiscountPaise: $this->money($this->maxDiscount, 'maxDiscount'),
            minOrderPaise: $this->money($this->minOrder, 'minOrder'),
            maxRedemptions: $this->count($this->maxRedemptions, 'maxRedemptions'),
            perMemberLimit: $this->count($this->perMemberLimit, 'perMemberLimit') ?? 0,
            firstTimeOnly: $this->firstTimeOnly,
            planCodes: $this->planCodes === [] ? null : array_values($this->planCodes),
            startsAt: $this->moment($this->startsAt, 'startsAt'),
            endsAt: $this->moment($this->endsAt, 'endsAt'),
        );
    }

    /** @throws ValidationException */
    private function money(string $input, string $field): ?int
    {
        if (trim($input) === '') {
            return null;
        }

        return Money::parseRupees($input) ?? throw ValidationException::withMessages([$field => __('Enter an amount in rupees.')]);
    }

    /** @throws ValidationException */
    private function count(string $input, string $field): ?int
    {
        if (trim($input) === '') {
            return null;
        }
        if (preg_match('/^\d{1,7}$/', trim($input)) !== 1) {
            throw ValidationException::withMessages([$field => __('Enter a whole number.')]);
        }

        return (int) $input;
    }

    /** @throws ValidationException */
    private function moment(string $input, string $field): ?CarbonImmutable
    {
        if (trim($input) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($input, (string) config('oppam.display_timezone'));
        } catch (Throwable) {
            throw ValidationException::withMessages([$field => __('Enter a date and time.')]);
        }
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'code', 'type', 'value', 'maxDiscount', 'minOrder', 'maxRedemptions', 'perMemberLimit', 'firstTimeOnly', 'planCodes', 'startsAt', 'endsAt');
        $this->resetErrorBag();
    }

    private function find(string $code): Coupon
    {
        return Coupon::query()->where('code', $code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
