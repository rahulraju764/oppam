<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\SaveJourney;
use App\Models\AdminUser;
use App\Models\AutomatedJourney;
use App\Models\CommunicationTemplate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Configures lifecycle automated journey rules (PRD §11 A14).
 */
#[Layout('layouts::admin', ['title' => 'Automated Journeys'])]
final class Journeys extends Component
{
    public bool $showModal = false;

    #[Locked]
    public ?string $editingId = null;

    public string $editingName = '';

    public bool $isActive = true;

    public int $triggerDays = 3;

    public string $channel = 'email';

    public ?string $templateId = null;

    public function mount(): void
    {
        $this->authorize('campaigns.view');
        $this->seedDefaultsIfMissing();
    }

    public function edit(string $id): void
    {
        /** @var AutomatedJourney $journey */
        $journey = AutomatedJourney::query()->findOrFail($id);

        $this->editingId = $journey->id;
        $this->editingName = $journey->name;
        $this->isActive = $journey->is_active;
        $this->triggerDays = $journey->trigger_days;
        $this->channel = $journey->channel;
        $this->templateId = $journey->template_id;
        $this->showModal = true;
    }

    public function toggleActive(string $id, SaveJourney $action): void
    {
        $admin = $this->admin();
        /** @var AutomatedJourney $journey */
        $journey = AutomatedJourney::query()->findOrFail($id);

        $action->handle($admin, $journey, ['is_active' => ! $journey->is_active]);
        $this->dispatch('toast', type: 'info', message: __('Journey status updated.'));
    }

    public function save(SaveJourney $action): void
    {
        $this->validate([
            'triggerDays' => ['required', 'integer', 'min:1', 'max:365'],
            'channel' => ['required', 'in:email,sms,in_app'],
            'templateId' => ['nullable', 'exists:communication_templates,id'],
        ]);

        $admin = $this->admin();
        /** @var AutomatedJourney $journey */
        $journey = AutomatedJourney::query()->findOrFail($this->editingId);

        $action->handle($admin, $journey, [
            'is_active' => $this->isActive,
            'trigger_days' => $this->triggerDays,
            'channel' => $this->channel,
            'template_id' => $this->templateId,
        ]);

        $this->showModal = false;
        $this->dispatch('toast', type: 'success', message: __('Journey rule updated.'));
    }

    public function render(): View
    {
        return view('livewire.admin.comms.journeys', [
            'journeys' => AutomatedJourney::query()->with('template')->get(),
            'templates' => CommunicationTemplate::query()->where('channel', $this->channel)->get(),
        ]);
    }

    private function seedDefaultsIfMissing(): void
    {
        $defaults = [
            'wizard_incomplete' => ['Incomplete Registration Wizard', 'Reminds members who left the registration wizard steps', 3, 'email'],
            'pending_interest' => ['Unanswered Interest Reminder', 'Reminds recipients of pending interests after N days', 3, 'in_app'],
            'plan_expiry' => ['Subscription Expiry Reminder', 'Notifies members before their paid plan ends', 7, 'email'],
            'win_back' => ['Win-back Expired Members', 'Sends special reactivation offer to lapsed subscribers', 30, 'email'],
            'inactive_matches' => ['Inactive Member Matches', 'Suggests fresh daily matches to members inactive for N days', 14, 'email'],
        ];

        foreach ($defaults as $key => [$name, $desc, $days, $chan]) {
            AutomatedJourney::query()->firstOrCreate(
                ['key' => $key],
                ['name' => $name, 'description' => $desc, 'trigger_days' => $days, 'channel' => $chan, 'is_active' => true]
            );
        }
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $user */
        $user = Auth::guard('admin')->user();

        return $user;
    }
}
