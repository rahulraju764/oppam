<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Comms;

use App\Actions\Admin\Comms\SaveJourney;
use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use App\Enums\JourneySendStatus;
use App\Models\AdminUser;
use App\Models\AutomatedJourney;
use App\Models\JourneySend;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A14 automated journeys (P8.3b2): each journey's on / off, day offsets and channel, with what it
 * did in the last 30 days, plus the built-in plan-expiry reminders (P5.2), read-only. Viewing needs
 * `campaigns.view`; changing needs `campaigns.send` (SaveJourney) and a reason. Nothing is written
 * on a page load.
 */
#[Layout('layouts::admin', ['title' => 'Automated journeys'])]
final class Journeys extends Component
{
    public const STATS_DAYS = 30;

    /** The journey being edited (a JourneyKey value), set server-side by edit(). */
    #[Locked]
    public ?string $editing = null;

    public bool $isActive = false;

    /** @var array<array-key, mixed> up to three day offsets, as the browser sent them (validated by SaveJourney) */
    public array $offsets = [];

    public string $channel = '';

    public string $reason = '';

    public function mount(): void
    {
        $this->authorize('campaigns.view');
    }

    public function edit(string $key): void
    {
        $this->authorize('campaigns.send');
        $journeyKey = JourneyKey::tryFrom($key);
        abort_if($journeyKey === null, 404);
        $journey = AutomatedJourney::query()->where('key', $journeyKey->value)->firstOrFail();

        $this->resetValidation();
        $this->editing = $journeyKey->value;
        $this->isActive = $journey->is_active;
        $this->offsets = array_pad($journey->offsets, SaveJourney::MAX_OFFSETS, '');
        $this->channel = $journey->channel->value;
        $this->reason = '';
        $this->dispatch('open-modal', name: 'journey-edit');
    }

    public function save(SaveJourney $action): void
    {
        $key = JourneyKey::tryFrom((string) $this->editing);
        abort_if($key === null, 404);

        // Empty boxes are dropped; anything else (even a tampered null / array) goes to SaveJourney's validator.
        $offsets = array_values(array_filter($this->offsets, fn (mixed $d): bool => $d !== '' && $d !== null));
        $action->handle($this->admin(), $key, $this->isActive, $offsets, $this->channel, $this->reason);

        $this->editing = null;
        $this->reason = '';
        $this->dispatch('close-modal', name: 'journey-edit');
        $this->dispatch('toast', type: 'success', message: __('Journey saved. The next daily run uses it.'));
    }

    public function render(): View
    {
        $journeys = AutomatedJourney::query()->get()->keyBy(fn (AutomatedJourney $j): string => $j->key->value);

        $stats = JourneySend::query()
            ->where('created_at', '>=', now()->subDays(self::STATS_DAYS))
            ->selectRaw('journey_key, status, COUNT(*) as n')
            ->groupBy('journey_key', 'status')
            ->toBase()->get()
            ->groupBy('journey_key')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [(string) $r->status => (int) $r->n]));

        $editingKey = JourneyKey::tryFrom((string) $this->editing);

        return view('livewire.admin.comms.journeys', [
            'keys' => JourneyKey::byPriority(),
            'journeys' => $journeys,
            'stats' => $stats,
            'sent' => JourneySendStatus::Sent->value,
            'canEdit' => Gate::forUser($this->admin())->allows('campaigns.send'),
            'editingKey' => $editingKey,
            'channelOptions' => $editingKey === null ? [] : collect(CommsChannel::cases())
                ->filter(fn (CommsChannel $c): bool => $editingKey->event()->usesChannel($c->value))
                ->mapWithKeys(fn (CommsChannel $c): array => [$c->value => $c->label()])->all(),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
