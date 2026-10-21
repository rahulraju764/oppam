<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Chat;

use App\Queries\Admin\ChatOverview;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * A13 conversation overview (PRD A13 "Conversation overview (metadata only)"): messages per day,
 * top senders, one-sided threads, new accounts messaging many members — counts and member codes,
 * never message text (ChatOverview, cached; "Refresh" re-reads it). Needs `chat.view_flagged`.
 */
#[Layout('layouts::admin', ['title' => 'Conversation overview'])]
final class Overview extends Component
{
    public function mount(): void
    {
        Gate::authorize('chat.view_flagged');
    }

    public function refreshFigures(ChatOverview $overview): void
    {
        Gate::authorize('chat.view_flagged');
        $overview->forget();
    }

    public function render(ChatOverview $overview): View
    {
        $data = $overview->get();

        return view('livewire.admin.chat.overview', [
            'data' => $data,
            'chart' => ['id' => 'messages', 'title' => __('Messages per day (30 days)'), 'type' => 'bar', 'labels' => $data->perDay['labels'], 'datasets' => [
                ['label' => __('Messages'), 'data' => $data->perDay['values']],
            ]],
            'canViewMembers' => auth('admin')->user()?->can('members.view') ?? false,
        ]);
    }
}
