{{-- A08 contact inbox (P8.1b): the list shows who and what about; the message is read in the drawer. --}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Contact inbox') }}</h1>
            <p>{{ __('Messages from the public contact form. Reply by email, or turn one into a support ticket.') }}</p>
        </div>
    </div>

    <div class="ui-card mb-4">
        <div class="row g-3 align-items-end">
            <div class="col-md-8">
                <nav class="admin-tabs" aria-label="{{ __('Contact messages') }}">
                    @foreach ($tabs as $value => $label)
                        <button type="button" @class(['admin-tab', 'active' => $tab === $value]) wire:click="$set('tab', '{{ $value }}')"
                                @if ($tab === $value) aria-current="page" @endif dusk="contact-tab-{{ $value }}">{{ $label }}</button>
                    @endforeach
                </nav>
            </div>
            <div class="col-md-4">
                <x-ui.input :label="__('Reference or email')" name="q" id="contactSearch" wire:model.live.debounce.400ms="search" maxlength="191" />
            </div>
        </div>
    </div>

    <div wire:loading.class="is-loading" wire:target="tab, search, gotoPage, nextPage, previousPage">
        <x-admin.table :headers="[__('Reference'), __('From'), __('Topic'), __('Status'), __('Received (IST)'), __('Assigned'), '']" :caption="__('Contact messages')" :empty="$this->submissions->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-envelope-open-o" :title="__('Nothing here')" :message="__('No contact messages in this tab.')" />
            </x-slot:emptyState>

            @foreach ($this->submissions as $row)
                <tr wire:key="contact-{{ $row->code }}">
                    <td><strong>{{ $row->code }}</strong></td>
                    <td>{{ $row->name }}<div class="small text-muted-brand">{{ $row->email }}</div></td>
                    <td>{{ $row->category->label() }}</td>
                    <td>
                        <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                        @if ($row->spam_reason)<div class="small text-muted-brand">{{ $row->spam_reason }}</div>@endif
                    </td>
                    <td>{{ $row->created_at->timezone($timezone)->format('d M, g:i a') }}</td>
                    <td>{{ $row->assignee?->name ?? '—' }}</td>
                    <td class="text-end">
                        <x-ui.button size="sm" type="button" wire:click="open('{{ $row->code }}')" wire:loading.attr="disabled" wire:target="open('{{ $row->code }}')">{{ __('Open') }}</x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        {{ $this->submissions->links() }}
    </div>

    <x-admin.drawer name="contact-message" :title="$current ? $current->code : __('Message')">
        @if ($current)
            <dl class="mod-facts">
                <dt>{{ __('From') }}</dt><dd>{{ $current->name }} &lt;{{ $current->email }}&gt;</dd>
                <dt>{{ __('Topic') }}</dt><dd>{{ $current->category->label() }}</dd>
                <dt>{{ __('Received') }}</dt><dd>{{ $current->created_at->timezone($timezone)->format('d M Y, g:i a') }}</dd>
                <dt>{{ __('Status') }}</dt><dd><x-ui.badge :variant="$current->status->badgeVariant()">{{ $current->status->label() }}</x-ui.badge></dd>
                <dt>{{ __('Assigned') }}</dt><dd>{{ $current->assignee?->name ?? '—' }}</dd>
                @if ($current->ticket)
                    <dt>{{ __('Ticket') }}</dt><dd><a href="{{ route('admin.support.tickets.show', ['ticket' => $current->ticket->code]) }}" wire:navigate>{{ $current->ticket->code }}</a></dd>
                @endif
            </dl>

            <div class="ui-card mb-3">
                <p class="mb-0 contact-message__text">{{ $current->message }}</p>
            </div>

            @foreach ($current->replies as $sent)
                <div class="ui-card mb-2" wire:key="reply-{{ $sent->id }}">
                    <div class="small text-muted-brand">{{ __(':name replied :date', ['name' => $sent->admin?->name ?? __('An admin'), 'date' => $sent->created_at->timezone($timezone)->format('d M, g:i a')]) }}</div>
                    <p class="mb-0 contact-message__text">{{ $sent->body }}</p>
                </div>
            @endforeach

            @can('support.respond')
                @if (! in_array($current->status, [\App\Enums\ContactStatus::Spam, \App\Enums\ContactStatus::Converted], true))
                    <form wire:submit="sendReply" class="mt-3">
                        <x-ui.textarea :label="__('Reply by email')" name="reply" id="contactReply" wire:model="reply" rows="5" maxlength="5000" />
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="sendReply">{{ __('Send reply') }}</x-ui.button>
                            @if ($current->assigned_admin_id !== auth('admin')->id())
                                <x-ui.button type="button" variant="outline" wire:click="assignToMe" wire:loading.attr="disabled" wire:target="assignToMe">{{ __('Assign to me') }}</x-ui.button>
                            @endif
                            <x-ui.button type="button" variant="outline" wire:click="convert" wire:loading.attr="disabled" wire:target="convert">{{ __('Convert to ticket') }}</x-ui.button>
                            <x-ui.button type="button" variant="ghost" wire:click="markSpam(true)" wire:loading.attr="disabled" wire:target="markSpam">{{ __('Spam') }}</x-ui.button>
                        </div>
                    </form>
                @elseif ($current->status === \App\Enums\ContactStatus::Spam)
                    <x-ui.button type="button" variant="outline" wire:click="markSpam(false)" wire:loading.attr="disabled" wire:target="markSpam">{{ __('Not spam') }}</x-ui.button>
                @endif
            @endcan
        @endif
    </x-admin.drawer>
</div>
