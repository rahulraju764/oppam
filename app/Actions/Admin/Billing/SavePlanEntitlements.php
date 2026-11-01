<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Jobs\Billing\ApplyPlanEntitlements;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\PlanFeature;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 entitlement matrix (`billing.plans.edit`, audited): what a plan enforces. Unlike a price it
 * applies at once — ApplyPlanEntitlements tells the plan's running members (EntitlementsChanged)
 * after commit. Free members have no subscription row: their pages re-read the limits on next load.
 */
final class SavePlanEntitlements
{
    use AuthorizesBilling;

    private const MAX_LIMIT = 1_000_000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, array{enabled: bool, limit: int|null}>  $rules  by Entitlement value; limit null = unlimited
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Plan $plan, array $rules): void
    {
        $this->authorizeFor($admin, 'billing.plans.edit');

        foreach (Entitlement::cases() as $entitlement) {
            $limit = $rules[$entitlement->value]['limit'] ?? null;
            if ($limit !== null && ($limit < 0 || $limit > self::MAX_LIMIT)) {
                throw ValidationException::withMessages(['rules.'.$entitlement->value => __('Enter a limit from 0 to :max, or leave it empty for unlimited.', ['max' => number_format(self::MAX_LIMIT)])]);
            }
        }

        DB::transaction(function () use ($admin, $plan, $rules): void {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $features = $locked->features()->lockForUpdate()->get()->keyBy(fn (PlanFeature $f): string => $f->entitlement->value);
            $before = $features->map(fn (PlanFeature $f): array => ['enabled' => $f->is_enabled, 'limit' => $f->limit_value])->all();
            $after = [];

            foreach (Entitlement::cases() as $entitlement) {
                $rule = $rules[$entitlement->value] ?? ['enabled' => false, 'limit' => null];
                $row = $features->get($entitlement->value) ?? (new PlanFeature)->forceFill(['plan_id' => $locked->id, 'entitlement' => $entitlement]);
                $row->forceFill(['is_enabled' => $rule['enabled'], 'limit_value' => $entitlement->isFlag() ? null : $rule['limit']])->save();
                $after[$entitlement->value] = ['enabled' => $row->is_enabled, 'limit' => $row->limit_value];
            }

            $this->audit->record('billing.plan_entitlements_saved', $locked, $before, $after, actor: $admin, subjectLabel: $locked->code->value);

            if ($locked->code !== PlanCode::Free) {
                ApplyPlanEntitlements::dispatch($locked->id)->afterCommit();
            }
        });
    }
}
