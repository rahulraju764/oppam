<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Support;

use App\Actions\Admin\Support\SaveCannedResponse;
use App\Domain\Support\CannedResponseRenderer;
use App\Enums\TicketCategory;
use App\Models\AdminUser;
use App\Models\SupportCannedResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * /support/canned — the saved replies agents insert into tickets (PRD A10 "canned responses with
 * variables"). List, create, edit, delete — all through SaveCannedResponse (`support.respond`,
 * audited). The variables an agent may use are listed on the page.
 */
#[Layout('layouts::admin', ['title' => 'Canned responses'])]
final class CannedResponses extends Component
{
    #[Locked]
    public ?string $editingId = null;

    public string $title = '';

    public string $body = '';

    public string $category = '';

    public function mount(): void
    {
        Gate::authorize('support.respond');
    }

    public function edit(string $id): void
    {
        $response = SupportCannedResponse::query()->find($id) ?? abort(404);
        $this->editingId = $response->id;
        $this->title = $response->title;
        $this->body = $response->body;
        $this->category = $response->category->value ?? '';
    }

    public function cancel(): void
    {
        $this->reset('editingId', 'title', 'body', 'category');
        $this->resetErrorBag();
    }

    public function save(SaveCannedResponse $save): void
    {
        $existing = $this->editingId !== null ? (SupportCannedResponse::query()->find($this->editingId) ?? abort(404)) : null;

        try {
            $save->save($this->admin(), $existing, $this->title, $this->body, $this->category);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        $this->cancel();
        $this->dispatch('toast', type: 'success', title: __('Canned response saved.'));
    }

    public function delete(string $id, SaveCannedResponse $save): void
    {
        $response = SupportCannedResponse::query()->find($id) ?? abort(404);

        try {
            $save->delete($this->admin(), $response);
        } catch (AuthorizationException) {
            $this->dispatch('toast', type: 'danger', title: __('You don’t have permission to do that.'));

            return;
        }

        if ($this->editingId === $id) {
            $this->cancel();
        }
        $this->dispatch('toast', type: 'success', title: __('Canned response deleted.'));
    }

    public function render(): View
    {
        return view('livewire.admin.support.canned-responses', [
            'responses' => SupportCannedResponse::query()->orderBy('title')->get(),
            'categories' => TicketCategory::options(),
            'variables' => CannedResponseRenderer::VARIABLES,
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
