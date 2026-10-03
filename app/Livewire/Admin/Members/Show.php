<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members;

use App\Actions\Admin\Members\DeleteMember;
use App\Actions\Admin\Members\GrantComplimentaryPlan;
use App\Actions\Admin\Members\ReactivateMember;
use App\Actions\Admin\Members\RestoreMember;
use App\Actions\Admin\Members\SetMemberProfileHidden;
use App\Actions\Admin\Members\SuspendMember;
use App\Enums\PlanCode;
use App\Enums\ProfileStatus;
use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Livewire\Admin\Members\Concerns\LoadsMember;
use App\Support\Facades\Settings;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A03 member detail: header + quick actions (suspend / reactivate, hide / unhide, delete with the
 * typed code / restore, grant a complimentary plan — each with a typed reason, each authorized
 * again in its Action) and lazy tabs: Profile, Photos, Subscriptions, Activity, Notes, Timeline.
 * Tabs for modules not built yet are added by those modules (owner decision 2026-10-02).
 */
#[Layout('layouts::admin', ['title' => 'Member'])]
final class Show extends Component
{
    use LoadsMember;

    public const TABS = ['overview', 'profile', 'photos', 'subscriptions', 'activity', 'notes', 'timeline'];

    #[Url(except: 'overview')]
    public string $tab = 'overview';

    public string $reason = '';

    public string $confirmCode = '';

    public string $grantPlan = '';

    public string $grantDays = '30';

    public function mount(string $profile): void
    {
        $this->authorize('members.view');
        $this->code = strtoupper($profile);
        $this->member();   // 404 for an unknown code

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function updatedTab(): void
    {
        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }
    }

    public function suspend(SuspendMember $action): void
    {
        $this->authorize('members.suspend');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), $this->reason), 'suspend', __('Member suspended.'));
    }

    public function reactivate(ReactivateMember $action): void
    {
        $this->authorize('members.suspend');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), $this->reason), 'reactivate', __('Member reactivated.'));
    }

    public function hide(SetMemberProfileHidden $action): void
    {
        $this->authorize('members.edit');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), true, $this->reason), 'hide', __('Profile hidden.'));
    }

    public function unhide(SetMemberProfileHidden $action): void
    {
        $this->authorize('members.edit');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), false, $this->reason), 'unhide', __('Profile visible again.'));
    }

    public function delete(DeleteMember $action): void
    {
        $this->authorize('members.delete');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), $this->confirmCode, $this->reason), 'delete', __('Member deleted. It can be restored for :days days.', [
            'days' => Settings::int(SettingKey::MembersPurgeAfterDays),
        ]));
    }

    public function restore(RestoreMember $action): void
    {
        $this->authorize('members.delete');
        $this->run(fn () => $action->handle($this->admin(), $this->member(), $this->reason), 'restore', __('Member restored.'));
    }

    public function grant(GrantComplimentaryPlan $action): void
    {
        $this->authorize('billing.force_activate');
        $this->validate([
            'grantPlan' => ['required', 'in:'.implode(',', [PlanCode::Silver->value, PlanCode::Gold->value, PlanCode::Diamond->value])],
            'grantDays' => ['required', 'integer', 'between:1,'.GrantComplimentaryPlan::MAX_DAYS],
        ]);

        $this->run(fn () => $action->handle($this->admin(), $this->member(), PlanCode::from($this->grantPlan), (int) $this->grantDays, $this->reason),
            'grant', __('Complimentary plan granted.'));
    }

    public function render(): View
    {
        $member = $this->member();
        $profile = $this->profile();

        return view('livewire.admin.members.show', [
            'member' => $member,
            'profile' => $profile,
            'isDeleted' => $member->trashed(),
            'isSuspended' => ! $member->trashed() && $member->status === UserStatus::Suspended,
            'isActive' => ! $member->trashed() && $member->status === UserStatus::Active,
            'canHide' => ! $member->trashed() && $profile->status === ProfileStatus::Active,
            'canUnhide' => ! $member->trashed() && $profile->status === ProfileStatus::Hidden,
            'restorableUntil' => $member->trashed() && $member->anonymised_at === null
                ? $member->deleted_at?->addDays(Settings::int(SettingKey::MembersPurgeAfterDays)) : null,
            'grantablePlans' => collect(PlanCode::options())->except(PlanCode::Free->value)->all(),
            'timezone' => (string) config('oppam.display_timezone'),
        ])->title($profile->code.' · '.__('Member'));
    }

    /** Run a quick action; a state conflict becomes a toast, success closes its dialog. */
    private function run(Closure $action, string $modal, string $done): void
    {
        try {
            $action();
        } catch (MemberStateConflict $conflict) {
            $this->dispatch('toast', type: 'error', message: $conflict->getMessage());

            return;
        }

        $this->reset('reason', 'confirmCode');
        $this->loadedMember = null;
        $this->dispatch('close-modal', name: 'member-'.$modal);
        $this->dispatch('toast', type: 'success', message: $done);
    }
}
