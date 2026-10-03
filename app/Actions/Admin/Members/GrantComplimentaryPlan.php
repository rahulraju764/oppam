<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\PlanCode;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Entitlements\EntitlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Grant a complimentary plan (A03 quick action): a COMPLIMENTARY subscription to a paid plan for
 * 1–365 days from now — no order, no payment. Needs `billing.force_activate` (it hands out paid
 * features; decision 2026-10-02). Active accounts with a verified phone only. The profile is marked premium; the
 * expiry sweep that clears it again arrives with P5.2. Typed reason, audited.
 */
final class GrantComplimentaryPlan
{
    use ChangesMemberState;

    public const MAX_DAYS = 365;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, PlanCode $code, int $days, string $reason): Subscription
    {
        Gate::forUser($admin)->authorize('billing.force_activate');
        $reason = $this->validatedReason($reason);

        if ($code === PlanCode::Free) {
            throw ValidationException::withMessages(['grantPlan' => __('Choose a paid plan.')]);
        }

        if ($days < 1 || $days > self::MAX_DAYS) {
            throw ValidationException::withMessages(['grantDays' => __('Choose between 1 and :max days.', ['max' => self::MAX_DAYS])]);
        }

        $plan = Plan::query()->where('code', $code->value)->firstOrFail();

        $subscription = DB::transaction(function () use ($admin, $member, $plan, $days, $reason): Subscription {
            $member = $this->lockMember($member);
            if ($member->trashed() || $member->status !== UserStatus::Active) {
                throw MemberStateConflict::notActive();
            }

            // A never-verified registration may be released (hard-deleted) when the number's real
            // owner registers; a subscription would block that release.
            if (! $member->hasVerifiedPhone()) {
                throw MemberStateConflict::phoneNotVerified();
            }

            $profile = $this->lockProfile($member) ?? throw MemberStateConflict::notActive();

            $subscription = new Subscription;
            $subscription->forceFill([
                'profile_id' => $profile->id,
                'plan_id' => $plan->id,
                'status' => SubscriptionStatus::Active,
                'source' => SubscriptionSource::Complimentary,
                'starts_at' => now(),
                'ends_at' => now()->addDays($days),
            ])->save();

            $profile->forceFill(['is_premium' => true])->save();
            $this->entitlements->forget($profile);

            $this->audit->record('members.plan_granted', $subscription, after: [
                'plan' => $plan->code->value, 'days' => $days, 'ends_at' => $subscription->ends_at->toIso8601String(),
            ], reason: $reason, actor: $admin, subjectLabel: $profile->code);

            return $subscription;
        });

        return $subscription;
    }
}
