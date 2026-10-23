{{--
    A10 ticket: thread (internal notes marked, never shown to the member) + reply box with canned
    responses (left), member context sidebar (right).
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ $ticket->subject }}</h1>
            <p>
                <a href="{{ route('admin.support.tickets') }}" wire:navigate><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back to tickets') }}</a>
                · {{ $ticket->code }} · {{ $ticket->source->label() }}
                · <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->label() }}</x-ui.badge>
                @if ($ticket->isFirstResponseOverdue())<x-ui.badge variant="danger" icon="fa-clock-o">{{ __('First reply overdue') }}</x-ui.badge>@endif
            </p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="ui-card">
                <ol class="ticket-thread" aria-label="{{ __('Messages in this ticket') }}">
                    @foreach ($ticket->messages as $line)
                        <li @class(['ticket-thread__item', 'is-staff' => $line->author === \App\Enums\TicketAuthor::Admin && ! $line->is_internal, 'is-note' => $line->is_internal]) wire:key="line-{{ $line->id }}">
                            <span class="ticket-thread__meta">
                                @if ($line->author === \App\Enums\TicketAuthor::Admin)
                                    {{ $line->admin?->name ?? __('Support') }}
                                    @if ($line->is_internal)<x-ui.badge variant="warning" icon="fa-lock">{{ __('Internal note') }}</x-ui.badge>@endif
                                @else
                                    {{ $ticket->name }}
                                @endif
                                · {{ $line->created_at?->timezone($timezone)->format('d M, g:i a') }}
                            </span>
                            <p class="ticket-thread__body">{{ $line->body }}</p>
                        </li>
                    @endforeach
                </ol>

                @if ($canRespond && $ticket->status->acceptsReplies())
                    <form wire:submit="send" class="mt-3" novalidate>
                        @if ($cannedOptions !== [])
                            <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
                                <div class="flex-grow-1">
                                    <x-ui.select :label="__('Canned response')" name="canned" id="ticketCanned" wire:model="canned" :options="$cannedOptions" :placeholder="__('Choose one')" />
                                </div>
                                <x-ui.button size="sm" variant="outline" type="button" wire:click="useCanned" loading="useCanned">{{ __('Insert') }}</x-ui.button>
                            </div>
                        @endif
                        <x-ui.textarea :label="$internal ? __('Internal note (only admins see this)') : __('Reply to :name', ['name' => $ticket->name])" name="reply" id="ticketReplyBody"
                                       wire:model="reply" error="body" rows="6" maxlength="5000" required />
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="ticketInternal" name="internal" wire:model.live="internal">
                            <label class="form-check-label" for="ticketInternal">{{ __('Internal note — not sent to the member') }}</label>
                        </div>
                        <x-ui.button type="submit" loading="send" class="mt-2" :icon="$internal ? 'fa-lock' : 'fa-reply'">{{ $internal ? __('Add note') : __('Send reply') }}</x-ui.button>
                    </form>
                @elseif (! $ticket->status->acceptsReplies())
                    <x-ui.alert type="info" class="mt-3">{{ __('This ticket is closed.') }}</x-ui.alert>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="ui-card">
                <h2 class="admin-section-title">{{ __('Ticket') }}</h2>
                <dl class="mod-facts">
                    <dt>{{ __('Topic') }}</dt><dd>{{ $ticket->category->label() }}</dd>
                    <dt>{{ __('Opened (IST)') }}</dt><dd>{{ $ticket->created_at?->timezone($timezone)->format('d M Y, g:i a') }}</dd>
                    <dt>{{ __('First reply due') }}</dt><dd>{{ $ticket->first_responded_at ? __('Replied :date', ['date' => $ticket->first_responded_at->timezone($timezone)->format('d M, g:i a')]) : $ticket->first_response_due_at->timezone($timezone)->format('d M, g:i a') }}</dd>
                    <dt>{{ __('Assigned to') }}</dt><dd>{{ $ticket->assignee?->name ?? '—' }}</dd>
                    <dt>{{ __('Contact') }}</dt><dd>{{ $ticket->email ?? '—' }}</dd>
                </dl>
                @if ($canRespond)
                    <div class="row g-2">
                        <div class="col-12">
                            <x-ui.select :label="__('Status')" name="ticketStatusSet" id="ticketStatusSet" :options="$statuses" :selected="$ticket->status->value"
                                         x-on:change="$wire.setStatus($event.target.value)" />
                        </div>
                        <div class="col-12">
                            <x-ui.select :label="__('Topic')" name="ticketCategorySet" id="ticketCategorySet" :options="$categories" :selected="$ticket->category->value"
                                         x-on:change="$wire.setCategory($event.target.value)" />
                        </div>
                    </div>
                    <x-ui.button size="sm" variant="outline" type="button" class="mt-3" wire:click="assignToMe" loading="assignToMe">{{ __('Assign to me') }}</x-ui.button>
                @endif
            </div>

            <div class="ui-card mt-4">
                <h2 class="admin-section-title">{{ __('Member') }}</h2>
                @if ($profile)
                    <dl class="mod-facts">
                        <dt>{{ __('Profile') }}</dt>
                        <dd>@if ($memberUrl)<a href="{{ $memberUrl }}" wire:navigate>{{ $profile->code }}</a>@else{{ $profile->code }}@endif · {{ $profile->status->label() }}</dd>
                        <dt>{{ __('Account') }}</dt><dd>{{ $ticket->user?->status->label() }}</dd>
                        <dt>{{ __('Plan') }}</dt><dd>{{ $plan }}</dd>
                        <dt>{{ __('Blocks (read only)') }}</dt><dd>{{ __(':made made · :received received', ['made' => $blocksMade, 'received' => $blocksReceived]) }}</dd>
                    </dl>
                @else
                    <p class="small text-muted-brand">{{ __('Sent from the contact form without an account.') }}</p>
                @endif

                @if ($activity !== [])
                    <p class="small mb-1"><strong>{{ __('Recent activity (when the ticket was opened)') }}</strong></p>
                    <ul class="small list-unstyled mb-0">
                        @foreach ($activity as $i => $row)
                            <li wire:key="ctx-{{ $i }}">{{ $row['action'] }} · {{ $row['at'] }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($otherTickets->isNotEmpty())
                    <p class="small mb-1 mt-3"><strong>{{ __('Other tickets') }}</strong></p>
                    <ul class="small list-unstyled mb-0">
                        @foreach ($otherTickets as $other)
                            <li wire:key="other-{{ $other->code }}"><a href="{{ route('admin.support.tickets.show', ['ticket' => $other->code]) }}" wire:navigate>{{ $other->code }}</a> · {{ \Illuminate\Support\Str::limit($other->subject, 40) }} · {{ $other->status->label() }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    </div>
</div>
