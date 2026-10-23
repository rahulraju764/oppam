{{-- /help/{ticket}: the member's own request — our replies and theirs, oldest first; never internal notes. --}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container is-readable">
            <div class="d-head daily-head">
                <h1>{{ $ticket->subject }}</h1>
                <p class="daily-head__expiry">
                    {{ $ticket->code }} · {{ $ticket->category->label() }} ·
                    <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->memberLabel() }}</x-ui.badge>
                </p>
            </div>

            <div class="ui-card">
                <ol class="ticket-thread" aria-label="{{ __('Messages in this request') }}">
                    @foreach ($messages as $line)
                        <li @class(['ticket-thread__item', 'is-staff' => $line->author === \App\Enums\TicketAuthor::Admin]) wire:key="line-{{ $line->id }}">
                            <span class="ticket-thread__meta">
                                {{ $line->author === \App\Enums\TicketAuthor::Admin ? __('Oppam support') : __('You') }}
                                · {{ $line->created_at?->timezone($timezone)->format('d M, g:i a') }}
                            </span>
                            <p class="ticket-thread__body">{{ $line->body }}</p>
                        </li>
                    @endforeach
                </ol>

                @if ($ticket->status->acceptsReplies())
                    <form wire:submit="send" class="mt-3" novalidate>
                        <x-ui.textarea :label="__('Your reply')" name="reply" id="ticketReply" wire:model="reply" error="body" rows="4" maxlength="5000" required />
                        <x-ui.button type="submit" loading="send" icon="fa-reply" class="mt-2">{{ __('Send reply') }}</x-ui.button>
                    </form>
                @else
                    <x-ui.alert type="info" class="mt-3">{{ __('This request is closed. Need more help?') }} <a href="{{ route('member.help') }}" wire:navigate>{{ __('Open a new request') }}</a></x-ui.alert>
                @endif
            </div>
        </div>
    </section>
</div>
