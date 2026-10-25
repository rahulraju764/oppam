<?php

declare(strict_types=1);

namespace App\Livewire\Broker\ManagedProfiles;

use App\Actions\Broker\ManagedProfiles\AddClientNote;
use App\Actions\Broker\ManagedProfiles\CompleteFollowUp;
use App\Data\Broker\ClientNoteData;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BureauPermission;
use App\Enums\ClientNoteType;
use App\Models\BrokerClientNote;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/profiles/{code}/notes (§11A B.6 "client notes & reminders", B.11 `client_notes.write`):
 * the bureau's notes, calls and meetings for one client, newest first, with follow-up dates and
 * "mark done". Internal to the bureau. The profile is found inside the caller's scope; the code
 * is locked; notes are escaped on output.
 */
final class Notes extends Component
{
    use WithPagination;

    private const PER_PAGE = 15;

    #[Locked]
    public string $code = '';

    public string $type = 'NOTE';

    public string $outcome = '';

    public string $body = '';

    public string $followUpOn = '';

    public function mount(string $code): void
    {
        $this->code = $code;
        $this->profile();
    }

    public function add(AddClientNote $add): void
    {
        $add->handle($this->user(), $this->code, new ClientNoteData(
            type: $this->type,
            outcome: $this->outcome === '' ? null : $this->outcome,
            body: $this->body,
            followUpOn: $this->followUpOn === '' ? null : $this->followUpOn,
        ));

        $this->reset('outcome', 'body', 'followUpOn');
        $this->type = ClientNoteType::Note->value;
        $this->resetPage();
        $this->dispatch('toast', type: 'success', message: __('Note saved.'));
    }

    public function complete(string $noteId, CompleteFollowUp $complete): void
    {
        $complete->handle($this->user(), $noteId);
        $this->dispatch('toast', type: 'success', message: __('Follow-up marked as done.'));
    }

    public function render(): View
    {
        $profile = $this->profile();

        $notes = BrokerClientNote::query()
            ->where('profile_id', $profile->id)
            ->where('broker_id', $profile->managed_by_broker_id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        // Author names in one query (no lazy loads per note).
        $authors = BrokerStaff::query()
            ->whereIn('user_id', $notes->getCollection()->pluck('author_user_id')->unique()->all())
            ->with(['user:id,phone', 'broker:id,name'])
            ->get()
            ->mapWithKeys(fn (BrokerStaff $s): array => [$s->user_id => $s->displayName()]);

        return view('livewire.broker.managed-profiles.notes', [
            'profile' => $profile,
            'notes' => $notes,
            'authors' => $authors,
            'types' => ClientNoteType::options(),
            'today' => now(config('oppam.display_timezone'))->format('Y-m-d'),
        ])->layout('layouts::broker', ['seo' => SeoData::private(__('Notes for :code', ['code' => $profile->code]).' | Oppam Matrimony')]);
    }

    private function profile(): Profile
    {
        $staff = app(BureauGate::class)->authorize($this->user(), BureauPermission::ClientNotesWrite);

        return app(ManagedProfileScope::class)->find($staff, $this->code);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
