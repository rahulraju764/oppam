{{--
    /help (PRD A10 in-app help): ask support (left) and the member's own requests (right).
    The plan and recent account activity are attached for the support team only.
--}}
<div>
    <section class="daily-section bg-coloring">
        <div class="container is-readable">
            <div class="d-head daily-head">
                <h1>{{ __('Help & support') }}</h1>
                <p class="daily-head__expiry">{{ __('Ask us anything about your account, profile or membership.') }}</p>
            </div>

            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="ui-card">
                        <h2 class="safety-list__title">{{ __('Ask our support team') }}</h2>
                        <form wire:submit="send" id="helpForm" novalidate>
                            <x-ui.select :label="__('Topic')" name="category" id="helpCategory" wire:model="category" :options="$categories"
                                         :placeholder="__('Choose a topic')" error="category" required />
                            <div class="mt-3">
                                <x-ui.input :label="__('Subject')" name="subject" id="helpSubject" wire:model="subject" error="subject" maxlength="150" required />
                            </div>
                            <div class="mt-3">
                                <x-ui.textarea :label="__('How can we help?')" name="message" id="helpMessage" wire:model="message" error="message" rows="5" maxlength="5000" required />
                            </div>
                            <p class="form-text">{{ __('To help us help you faster, your plan and recent account activity are shared with our support team.') }}</p>
                            <x-ui.button type="submit" loading="send" icon="fa-paper-plane">{{ __('Send') }}</x-ui.button>
                        </form>
                        <p class="form-text mt-3 mb-0">
                            {{ __('Worried about a member?') }} <a href="{{ route('safety') }}" wire:navigate>{{ __('Visit the Safety Centre') }}</a>
                        </p>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="ui-card">
                        <h2 class="safety-list__title">{{ __('Your requests') }}</h2>
                        <div wire:loading.class="is-loading" wire:target="gotoPage,nextPage,previousPage,send">
                            @if ($tickets->isEmpty())
                                <x-ui.empty-state icon="fa-life-ring" :title="__('No requests yet')" :message="__('When you ask us something, it appears here with our replies.')" />
                            @else
                                <ul class="safety-list">
                                    @foreach ($tickets as $ticket)
                                        <li class="safety-list__row" wire:key="ticket-{{ $ticket->code }}">
                                            <span class="safety-list__who">
                                                <a href="{{ route('member.help.ticket', ['ticket' => $ticket->code]) }}" wire:navigate><strong>{{ $ticket->subject }}</strong></a>
                                                <span class="text-muted-brand">{{ $ticket->code }} · {{ $ticket->category->label() }} · {{ $ticket->last_activity_at?->timezone($timezone)->format('d M, g:i a') }}</span>
                                            </span>
                                            <x-ui.badge :variant="$ticket->status->badgeVariant()">{{ $ticket->status->memberLabel() }}</x-ui.badge>
                                        </li>
                                    @endforeach
                                </ul>
                                {{ $tickets->links() }}
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
