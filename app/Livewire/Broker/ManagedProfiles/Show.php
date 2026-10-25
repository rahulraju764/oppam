<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Actions\Broker\ManagedProfiles\AssignManagedProfiles;
use App\Actions\Broker\ManagedProfiles\SetManagedProfileStatus;
use App\Actions\Broker\ManagedProfiles\SubmitManagedProfile;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Domain\Profile\WizardProgress;
use App\Enums\BureauPermission;
use App\Enums\ManagedProfileStatusChange;
use App\Enums\ProfileStatus;
use App\Exceptions\Broker\ManagedProfileRefused;
use App\Exceptions\Profile\ProfileNotSubmittable;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use App\Queries\Broker\ManagedProfileList;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /broker/profiles/{code} (§11A B.6 / B.11, `profiles.view`): a managed client's summary, its
 * moderation status and the moderator's reason, and the actions the caller's role allows — edit,
 * submit for review, deactivate / activate, delete / restore (30 days), assign. Every action is
 * an Action that checks its own permission and the scope again; the code is locked. Deleted
 * profiles stay viewable here for restore. Refreshes live on `profile.moderated`.
 */
final class Show extends Component
{
    #[Locked]
    public string $code = '';

    public string $assignTo = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $profile = $this->profile();
        $this->assignTo = (string) $profile->assigned_staff_id;
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff !== null ? ['echo-private:broker.'.$staff->broker_id.',.profile.moderated' => '$refresh'] : [];
    }

    public function change(string $change, SetManagedProfileStatus $status): void
    {
        $action = ManagedProfileStatusChange::tryFrom($change) ?? abort(404);

        try {
            $status->handle($this->user(), $this->code, $action);
        } catch (ManagedProfileRefused $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: $action->done());
    }

    public function submit(SubmitManagedProfile $submit): void
    {
        try {
            $submit->handle($this->user(), $this->code);
        } catch (ProfileNotSubmittable $e) {
            $this->dispatch('toast', type: 'error', message: $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Sent for review. You’ll be told as soon as our team decides.'));
    }

    public function assign(AssignManagedProfiles $assign): void
    {
        try {
            $assign->handle($this->user(), [$this->code], $this->assignTo === '' ? null : $this->assignTo);
        } catch (ManagedProfileRefused $e) {
            $this->addError('assignTo', $e->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Assignment saved.'));
    }

    public function render(BureauGate $gate, ManagedProfileList $list): View
    {
        $user = $this->user();
        $staff = $gate->authorize($user, BureauPermission::ProfilesView);
        $profile = $this->profile();
        $can = fn (BureauPermission $permission): bool => $gate->allows($user, $permission);
        $deleted = $profile->trashed();

        $actions = array_values(array_filter([
            ! $deleted && $profile->status === ProfileStatus::Active && $can(BureauPermission::ProfilesDeactivate) ? ManagedProfileStatusChange::Deactivate : null,
            ! $deleted && $profile->status === ProfileStatus::Hidden && $profile->published_at !== null && $can(BureauPermission::ProfilesActivate) ? ManagedProfileStatusChange::Activate : null,
            ! $deleted && ! in_array($profile->status, [ProfileStatus::Suspended, ProfileStatus::Deleted], true) && $can(BureauPermission::ProfilesDelete) ? ManagedProfileStatusChange::Delete : null,
            $deleted && $can(BureauPermission::ProfilesDelete) ? ManagedProfileStatusChange::Restore : null,
        ]));

        return view('livewire.broker.managed-profiles.show', [
            'profile' => $profile,
            'deleted' => $deleted,
            'progress' => new WizardProgress($profile),
            'decisionNote' => $profile->status === ProfileStatus::Rejected ? ModerationItem::latestDecisionNote($profile) : null,
            'maskedPhone' => $profile->client_phone !== null ? PhoneNumber::tryParse($profile->client_phone)?->masked() : null,
            'canEdit' => ! $deleted && $user->can('editWizard', $profile),
            'canSubmit' => ! $deleted && in_array($profile->status, [ProfileStatus::Draft, ProfileStatus::Rejected], true) && $can(BureauPermission::ProfilesSubmit),
            'canNotes' => ! $deleted && $can(BureauPermission::ClientNotesWrite),
            'canAssign' => ! $deleted && $can(BureauPermission::ProfilesAssign),
            'assignees' => $can(BureauPermission::ProfilesAssign) ? $list->assignees($staff)->mapWithKeys(fn ($s): array => [$s->id => $s->displayName()])->all() : [],
            'actions' => $actions,
            'restoreUntil' => $deleted ? $profile->deleted_at?->addDays((int) config('oppam.broker.managed_restore_days')) : null,
        ])->layout('layouts::broker', ['seo' => SeoData::private($profile->code.' | Oppam Matrimony')]);
    }

    private function profile(): Profile
    {
        $staff = app(BureauGate::class)->authorize($this->user(), BureauPermission::ProfilesView);

        return app(ManagedProfileScope::class)->query($staff, withTrashed: true)
            ->where('profiles.code', $this->code)
            ->with(['religion', 'caste', 'motherTongue', 'district', 'assignedStaff.user:id,phone', 'assignedStaff.broker:id,name'])
            ->firstOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
