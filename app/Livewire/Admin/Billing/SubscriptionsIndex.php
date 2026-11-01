<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Billing;

use App\Actions\Admin\Members\GrantComplimentaryPlan;
use App\Enums\PlanCode;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A06 subscription views: active, expiring in 7 days, expired in the last 30 (win-back), cancelled,
 * complimentary; plus a complimentary grant by profile code through the A03 Action (≤ 12 months,
 * reason, audited, `billing.force_activate`).
 */
#[Layout('layouts::admin', ['title' => 'Subscriptions'])]
final class SubscriptionsIndex extends Component
{
    use WithPagination;

    public const VIEWS = ['active', 'expiring', 'expired', 'cancelled', 'complimentary'];

    #[Url(except: 'active')]
    public string $view = 'active';

    public string $grantCode = '';

    public string $grantPlan = 'GOLD';

    public string $grantDays = '30';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('billing.view');
        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'active';
    }

    public function updatedView(): void
    {
        $this->view = in_array($this->view, self::VIEWS, true) ? $this->view : 'active';
        $this->resetPage();
    }

    public function grant(GrantComplimentaryPlan $grant): void
    {
        $this->resetErrorBag();
        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $profile = Profile::query()->where('code', strtoupper(trim($this->grantCode)))->first();
        $member = $profile?->user_id !== null ? User::query()->find($profile->user_id) : null;
        if ($member === null) {
            throw ValidationException::withMessages(['grantCode' => __('No member has this profile code.')]);
        }

        $plan = PlanCode::tryFrom($this->grantPlan) ?? throw ValidationException::withMessages(['grantPlan' => __('Choose a paid plan.')]);

        try {
            $grant->handle($admin, $member, $plan, (int) $this->grantDays, $this->reason);
        } catch (MemberStateConflict $conflict) {
            $this->dispatch('toast', type: 'danger', title: $conflict->getMessage());

            return;
        }

        $this->dispatch('close-modal', name: 'plan-grant');
        $this->dispatch('toast', type: 'success', title: __('Complimentary plan granted.'));
        $this->reset('grantCode', 'reason');
    }

    public function render(): View
    {
        return view('livewire.admin.billing.subscriptions-index', [
            'subscriptions' => $this->query()->with(['plan:id,code,name', 'profile:id,code'])->paginate(25),
            'views' => [
                'active' => __('Active'), 'expiring' => __('Expiring in 7 days'), 'expired' => __('Expired, 30 days'),
                'cancelled' => __('Cancelled'), 'complimentary' => __('Complimentary'),
            ],
            'plans' => array_values(array_filter(PlanCode::cases(), fn (PlanCode $p): bool => $p !== PlanCode::Free)),
            'maxDays' => GrantComplimentaryPlan::MAX_DAYS,
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /** @return Builder<Subscription> */
    private function query(): Builder
    {
        $running = fn (Builder $q) => $q->where('status', SubscriptionStatus::Active)->where('ends_at', '>', now());

        return match ($this->view) {
            'expiring' => $running(Subscription::query())->where('ends_at', '<=', now()->addDays(7))->orderBy('ends_at'),
            'expired' => Subscription::query()->where('status', SubscriptionStatus::Expired)->where('ends_at', '>=', now()->subDays(30))->orderByDesc('ends_at'),
            'cancelled' => Subscription::query()->where('status', SubscriptionStatus::Cancelled)->orderByDesc('cancelled_at'),
            'complimentary' => $running(Subscription::query())->where('source', SubscriptionSource::Complimentary)->orderBy('ends_at'),
            default => $running(Subscription::query())->orderBy('ends_at'),
        };
    }
}
