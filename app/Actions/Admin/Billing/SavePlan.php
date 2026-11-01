<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Data\Billing\PlanEditData;
use App\Enums\PlanCode;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 plan editor, the card and its prices (`billing.plans.edit`, audited). A price change reaches
 * new orders only: an order keeps the amounts it was created with. One plan is featured at a time.
 * The Free plan has no prices and is never purchasable.
 */
final class SavePlan
{
    use AuthorizesBilling;

    private const MAX_RUPEES = 200_000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Plan $plan, PlanEditData $data): Plan
    {
        $this->authorizeFor($admin, 'billing.plans.edit');
        $isFree = $plan->code === PlanCode::Free;
        $this->validate($data, $isFree);

        return DB::transaction(function () use ($admin, $plan, $data, $isFree): Plan {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $prices = $locked->prices()->lockForUpdate()->get()->keyBy('duration_months');
            $before = $this->snapshot($locked, $prices->all());

            if ($data->isFeatured && ! $isFree) {
                Plan::query()->whereKeyNot($locked->id)->where('is_featured', true)->update(['is_featured' => false]);
            }

            $locked->forceFill([
                'name' => trim($data->name),
                'badge' => $data->badge !== null && trim($data->badge) !== '' ? trim($data->badge) : null,
                'is_featured' => $data->isFeatured && ! $isFree,
                'is_purchasable' => $data->isPurchasable && ! $isFree,
                'display_features' => array_values(array_filter(array_map('trim', $data->displayFeatures), fn (string $l): bool => $l !== '')),
            ])->save();

            if (! $isFree) {
                foreach ($data->prices as $months => $price) {
                    $row = $prices->get($months) ?? (new PlanPrice)->forceFill(['plan_id' => $locked->id, 'duration_months' => $months]);
                    $row->forceFill(['price_paise' => $price['rupees'] * 100, 'is_active' => $price['active']])->save();
                }
            }

            $this->audit->record('billing.plan_saved', $locked, $before, $this->snapshot($locked, $locked->prices()->get()->keyBy('duration_months')->all()),
                actor: $admin, subjectLabel: $locked->code->value);

            return $locked;
        });
    }

    /** @throws ValidationException */
    private function validate(PlanEditData $data, bool $isFree): void
    {
        $name = trim($data->name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            throw ValidationException::withMessages(['name' => __('Give the plan a name of 2 to 60 characters.')]);
        }
        if ($data->badge !== null && mb_strlen(trim($data->badge)) > 30) {
            throw ValidationException::withMessages(['badge' => __('Keep the badge to 30 characters.')]);
        }
        if (count($data->displayFeatures) > 15) {
            throw ValidationException::withMessages(['displayFeatures' => __('A card shows at most 15 lines.')]);
        }
        foreach ($data->displayFeatures as $line) {
            if (mb_strlen($line) > 120) {
                throw ValidationException::withMessages(['displayFeatures' => __('Keep each line to 120 characters.')]);
            }
        }

        if ($isFree) {
            return;
        }

        foreach ($data->prices as $months => $price) {
            if ($price['rupees'] < 1 || $price['rupees'] > self::MAX_RUPEES) {
                throw ValidationException::withMessages(['prices.'.$months => __('Enter a price from ₹1 to ₹:max.', ['max' => number_format(self::MAX_RUPEES)])]);
            }
        }
        if ($data->isPurchasable && collect($data->prices)->doesntContain('active', true)) {
            throw ValidationException::withMessages(['prices' => __('A purchasable plan needs at least one active price.')]);
        }
    }

    /**
     * @param  array<int|string, PlanPrice>  $prices
     * @return array<string, mixed>
     */
    private function snapshot(Plan $plan, array $prices): array
    {
        return [
            'name' => $plan->name, 'badge' => $plan->badge, 'featured' => $plan->is_featured, 'purchasable' => $plan->is_purchasable,
            'display_features' => $plan->display_features,
            'prices' => array_map(fn (PlanPrice $p): array => ['paise' => $p->price_paise, 'active' => $p->is_active], $prices),
        ];
    }
}
