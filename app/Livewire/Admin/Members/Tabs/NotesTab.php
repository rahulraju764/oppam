<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Members\Tabs;

use App\Actions\Admin\Members\AddMemberNote;
use App\Livewire\Admin\Members\Concerns\IsMemberTab;
use App\Models\MemberNote;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** A03 "Notes (append-only)": internal notes on the member, newest first; add needs members.edit. */
final class NotesTab extends Component
{
    use IsMemberTab;

    public string $noteBody = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function add(AddMemberNote $add): void
    {
        $this->authorize('members.edit');
        $add->handle($this->admin(), $this->member(), $this->noteBody);
        $this->noteBody = '';
        $this->dispatch('toast', type: 'success', message: __('Note added.'));
    }

    public function render(): View
    {
        return view('livewire.admin.members.tabs.notes-tab', [
            'notes' => MemberNote::query()->where('user_id', $this->member()->id)->latest('created_at')->orderByDesc('id')->limit(100)->get(),
            'timezone' => (string) config('oppam.display_timezone'),
            'canAdd' => $this->admin()->can('members.edit'),
        ]);
    }
}
