{{-- A10 support tickets: the list. Message text is on the ticket page only. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Support tickets') }}</h1>
            <p>{{ __('Waiting for a first reply first, nearest deadline first.') }}
                @can('support.respond')<a href="{{ route('admin.support.canned') }}" wire:navigate>{{ __('Canned responses') }}</a>@endcan</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <x-ui.select :label="__('Status')" name="status" id="ticketStatus" wire:model.live="status" :options="$statuses" />
            </div>
            <div class="col-md-4">
                <x-ui.select :label="__('Topic')" name="category" id="ticketCategory" wire:model.live="category" :options="$categories" :placeholder="__('All topics')" />
            </div>
            <div class="col-md-4">
                <x-ui.input :label="__('Ticket code or email')" name="q" id="ticketSearch" wire:model.live.debounce.400ms="search" maxlength="120" />
            </div>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="status, category, search, gotoPage, nextPage, previousPage">
        <x-admin.table :headers="[__('Ticket'), __('From'), __('Topic'), __('Status'), __('First reply due (IST)'), __('Assigned'), '']" :caption="__('Support tickets')" :empty="$this->tickets->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-life-ring" :title="__('Nothing here')" :message="__('No tickets match these filters.')" />
            </x-slot:emptyState>

            @foreach ($this->tickets as $ticket)
                <tr wire:key="ticket-{{ $ticket->code }}" @class(['is-breach' => $ticket->isFirstResponseOverdue()])>
                    <td><strong>{{ $ticket->code }}</strong><div class="small">{{ \Illuminate\Support\Str::limit($ticket->subject, 60) }}</div></td>
                    <td>{{ $ticket->name }}<div class="small text-muted-brand">{{ $ticket->source->label() }}</div></td>
                    <td>{{ $ticket->category->label() }}</td>
                    <td><x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->label() }}</x-ui.badge></td>
                    <td>
                        @if ($ticket->first_responded_at)
                            <span class="small text-muted-brand">{{ __('Replied :date', ['date' => $ticket->first_responded_at->timezone($timezone)->format('d M, g:i a')]) }}</span>
                        @else
                            {{ $ticket->first_response_due_at->timezone($timezone)->format('d M, g:i a') }}
                            @if ($ticket->isFirstResponseOverdue())<div><x-ui.badge variant="danger" icon="fa-clock-o">{{ __('Overdue') }}</x-ui.badge></div>@endif
                        @endif
                    </td>
                    <td>{{ $ticket->assignee?->name ?? '—' }}</td>
                    <td class="text-end"><x-ui.button size="sm" :href="route('admin.support.tickets.show', ['ticket' => $ticket->code])" wire:navigate>{{ __('Open') }}</x-ui.button></td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->tickets->links() }}
    </div>
</div>
