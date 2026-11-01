<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Enums\PlanCode;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A06 "plan deactivate (never delete if referenced)": a deactivated plan leaves the catalogue while
 * running subscriptions carry on; delete only removes a plan no order or subscription points at.
 * `billing.plans.edit`, typed reason, audited. Free is the fallback for everyone: neither applies.
 */
final class ChangePlanAvailability
{
    use AuthorizesBilling;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused
     */
    public function setActive(AdminUser $admin, Plan $plan, bool $active, string $reason): Plan
    {
        $this->authorizeFor($admin, 'billing.plans.edit');
        $reason = $this->validatedReason($reason);
        $this->refuseFree($plan);

        return DB::transaction(function () use ($admin, $plan, $active, $reason): Plan {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            $before = ['active' => $locked->is_active];
            $locked->forceFill(['is_active' => $active])->save();
            $this->audit->record($active ? 'billing.plan_activated' : 'billing.plan_deactivated', $locked, $before, ['active' => $active],
                reason: $reason, actor: $admin, subjectLabel: $locked->code->value);

            return $locked;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused
     */
    public function delete(AdminUser $admin, Plan $plan, string $reason): void
    {
        $this->authorizeFor($admin, 'billing.plans.edit');
        $reason = $this->validatedReason($reason);
        $this->refuseFree($plan);

        DB::transaction(function () use ($admin, $plan, $reason): void {
            $locked = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (Order::query()->where('plan_id', $locked->id)->exists() || Subscription::query()->where('plan_id', $locked->id)->exists()) {
                throw BillingRefused::planInUse();
            }

            $this->audit->record('billing.plan_deleted', $locked, ['name' => $locked->name], reason: $reason, actor: $admin, subjectLabel: $locked->code->value);
            $locked->prices()->delete();
            $locked->features()->delete();
            $locked->delete();
        });
    }

    /** @throws BillingRefused */
    private function refuseFree(Plan $plan): void
    {
        if ($plan->code === PlanCode::Free) {
            throw BillingRefused::freePlan();
        }
    }
}
