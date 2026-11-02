<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Billing\SavePlan;
use App\Actions\Admin\Billing\SavePlanEntitlements;
use App\Data\Billing\PlanCardData;
use App\Data\Billing\PlanEditData;
use App\Domain\Billing\PlanConsistency;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Models\PlanPrice;
use App\ValueObjects\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A06 plan editor: the card with a live preview, prices by duration, the entitlement matrix and
 * the consistency warning. Prices and entitlements save separately because they take effect
 * differently (new orders only / at once). The preview is display only.
 */
#[Layout('layouts::admin', ['title' => 'Edit plan'])]
final class PlanEditor extends Component
{
    public const DURATIONS = [1, 3, 6, 12];

    #[Locked]
    public string $code = '';

    public string $name = '';

    public string $badge = '';

    public bool $isFeatured = false;

    public bool $isPurchasable = false;

    public string $features = '';

    /** @var array<int, array{rupees: string, active: bool}> */
    public array $prices = [];

    /** @var array<string, array{enabled: bool, limit: string}> */
    public array $rules = [];

    public function mount(string $plan): void
    {
        $this->authorize('billing.view');
        $row = Plan::query()->where('code', $plan)->with(['prices', 'features'])->firstOrFail();

        $this->code = $row->code->value;
        $this->name = $row->name;
        $this->badge = (string) $row->badge;
        $this->isFeatured = $row->is_featured;
        $this->isPurchasable = $row->is_purchasable;
        $this->features = implode("\n", $row->display_features);

        $prices = $row->prices->keyBy('duration_months');
        foreach (self::DURATIONS as $months) {
            /** @var PlanPrice|null $price */
            $price = $prices->get($months);
            $this->prices[$months] = ['rupees' => $price !== null ? (string) intdiv($price->price_paise, 100) : '', 'active' => $price->is_active ?? false];
        }

        $features = $row->features->keyBy(fn (PlanFeature $f): string => $f->entitlement->value);
        foreach (Entitlement::cases() as $entitlement) {
            /** @var PlanFeature|null $feature */
            $feature = $features->get($entitlement->value);
            $this->rules[$entitlement->value] = ['enabled' => $feature->is_enabled ?? false, 'limit' => $feature?->limit_value !== null ? (string) $feature->limit_value : ''];
        }
    }

    public function saveCard(SavePlan $save): void
    {
        $this->resetErrorBag();
        $prices = [];
        $offSale = [];
        foreach ($this->prices as $months => $price) {
            // A duration left empty stays off sale, or comes off it; a typed price must be a whole number of rupees.
            if (trim($price['rupees']) === '' && ! $price['active']) {
                $offSale[] = (int) $months;

                continue;
            }
            if (preg_match('/^\d{1,7}$/', trim($price['rupees'])) !== 1) {
                throw ValidationException::withMessages(['prices.'.$months => __('Enter a whole number of rupees.')]);
            }
            $prices[(int) $months] = ['rupees' => (int) $price['rupees'], 'active' => $price['active']];
        }

        $save->handle($this->admin(), $this->plan(), new PlanEditData($this->name, $this->badge, $this->isFeatured, $this->isPurchasable, $this->lines(), $prices, $offSale));
        $this->dispatch('toast', type: 'success', title: __('Plan saved. New orders use these prices.'));
    }

    public function saveEntitlements(SavePlanEntitlements $save): void
    {
        $this->resetErrorBag();
        $rules = [];
        foreach ($this->rules as $value => $rule) {
            $limit = trim($rule['limit']);
            if ($limit !== '' && preg_match('/^\d{1,7}$/', $limit) !== 1) {
                throw ValidationException::withMessages(['rules.'.$value => __('Enter a whole number, or leave it empty for unlimited.')]);
            }
            $rules[$value] = ['enabled' => $rule['enabled'], 'limit' => $limit === '' ? null : (int) $limit];
        }

        $save->handle($this->admin(), $this->plan(), $rules);
        $this->dispatch('toast', type: 'success', title: __('Entitlements saved. They apply now.'));
    }

    public function render(PlanConsistency $consistency): View
    {
        $rules = [];
        foreach ($this->rules as $value => $rule) {
            $rules[$value] = ['enabled' => $rule['enabled'], 'limit' => is_numeric($rule['limit']) ? (int) $rule['limit'] : null];
        }

        return view('livewire.admin.billing.plan-editor', [
            'preview' => $this->preview(),
            'warnings' => $consistency->warnings($this->lines(), $rules),
            'entitlements' => Entitlement::cases(),
            'isFree' => $this->code === PlanCode::Free->value,
            'durations' => self::DURATIONS,
        ]);
    }

    private function preview(): PlanCardData
    {
        $monthly = (int) ($this->prices[1]['rupees'] ?? 0);

        return new PlanCardData(
            key: strtolower($this->code),
            name: $this->name,
            durationMonths: 1,
            price: Money::rupees($monthly),
            perMonth: Money::rupees($monthly),
            savingsPercent: null,
            features: $this->lines(),
            isFeatured: $this->isFeatured,
            badge: trim($this->badge) !== '' ? $this->badge : null,
        );
    }

    /** @return list<string> */
    private function lines(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->features)), fn (string $l): bool => $l !== ''));
    }

    private function plan(): Plan
    {
        return Plan::query()->where('code', $this->code)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
