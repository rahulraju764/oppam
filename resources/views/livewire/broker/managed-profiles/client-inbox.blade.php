{{--
    /broker/profiles/{code}/inbox (§11A B.6 Client Inbox) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Received interests to accept / decline for the client, interests sent, and "send an interest" by profile code.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Client inbox') }}</h1>
                <p>{{ $client->fullName() }} · {{ $client->code }}</p>
            </div>

            <x-ui.alert type="info">{{ __('Chat stays closed until the client joins Oppam with their own mobile number. Members you connect with are told the bureau will contact them.') }}</x-ui.alert>

            <nav class="client-inbox__tabs" aria-label="{{ __('Inbox lists') }}">
                @foreach (['pending' => __('Waiting'), 'accepted' => __('Accepted'), 'declined' => __('Declined'), 'sent' => __('Sent')] as $key => $label)
                    <button type="button" wire:click="$set('tab', '{{ $key }}')" wire:key="tab-{{ $key }}"
                        @class(['client-inbox__tab', 'is-active' => $tab === $key]) @if ($tab === $key) aria-current="page" @endif>
                        {{ $label }} <span class="client-inbox__count">{{ $counts[$key] }}</span>
                    </button>
                @endforeach
            </nav>

            <div wire:loading.class="is-loading" wire:target="tab, accept, decline, gotoPage, nextPage, previousPage">
                @if ($cards === [])
                    <x-ui.empty-state icon="fa-inbox"
                        :title="$tab === 'sent' ? __('No interests sent yet') : __('Nothing here')"
                        :message="$tab === 'pending'
                            ? __('Interests members send to this client appear here. You can answer them after speaking to the family.')
                            : ($tab === 'sent' ? __('Send an interest below with the profile code the family chose.') : __('Answered interests appear here.'))" />
                @else
                    <ul class="client-inbox">
                        @foreach ($cards as $card)
                            <li class="client-inbox__item" wire:key="interest-{{ $card['code'] }}">
                                <div class="client-inbox__who">
                                    <strong>{{ $card['name'] }}</strong>
                                    <span class="client-inbox__code">{{ $card['code'] }}</span>
                                </div>
                                @if ($card['facts'] !== [])
                                    <p class="client-inbox__facts">{{ implode(' · ', $card['facts']) }}</p>
                                @endif
                                @if ($card['message'])
                                    <p class="client-inbox__message">“{{ $card['message'] }}”</p>
                                @endif
                                <div class="client-inbox__meta">
                                    @if ($card['at'])
                                        <span>{{ \Illuminate\Support\Carbon::parse($card['at'])->timezone(config('oppam.display_timezone'))->format('j M Y') }}</span>
                                    @endif
                                    @if ($tab === 'sent' && $card['status'])
                                        <x-ui.badge :variant="$card['status']->badge()">{{ $card['status']->label() }}</x-ui.badge>
                                    @endif
                                </div>
                                @if ($tab === 'pending')
                                    <div class="client-inbox__actions">
                                        <x-ui.button type="button" size="sm" icon="fa-check" wire:click="accept('{{ $card['code'] }}')" loading="accept">
                                            {{ __('Accept for client') }}
                                        </x-ui.button>
                                        <x-ui.button type="button" size="sm" variant="outline" wire:click="decline('{{ $card['code'] }}')" loading="decline">
                                            {{ __('Decline') }}
                                        </x-ui.button>
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <x-ui.pagination :paginator="$rows" :label="__('Inbox pages')" />
        </div>

        <div class="dashboard-content">
            <h2 class="managed-form__heading">{{ __('Send an interest for this client') }}</h2>
            <p class="form-text">{{ __('This month your bureau has used :used of :limit interests (shared by all your clients).', ['used' => $allowanceUsed, 'limit' => $allowanceLimit]) }}</p>

            @if (! $canSend)
                <x-ui.alert type="warning">{{ __('This client’s profile must be approved and active before you can send interests for them.') }}</x-ui.alert>
            @elseif ($allowanceUsed >= $allowanceLimit)
                <x-ui.alert type="warning">{{ __('Your bureau has used this month’s interests. The allowance renews on the 1st.') }}</x-ui.alert>
            @else
                <form wire:submit="send" novalidate>
                    <div class="row">
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="inboxTarget">{{ __('Profile code') }} *</label>
                                <input id="inboxTarget" name="target" type="text" maxlength="20" autocomplete="off" inputmode="text"
                                       wire:model="target" placeholder="OPM12345"
                                       @class(['form-control', 'is-invalid' => $errors->has('target')])
                                       @error('target') aria-invalid="true" aria-describedby="inboxTarget-error" @enderror>
                                @error('target') <p class="invalid-feedback d-block" id="inboxTarget-error" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                        <div class="col-md-6 col-12">
                            <div class="form-group">
                                <label for="inboxTemplate">{{ __('Message') }}</label>
                                <select id="inboxTemplate" name="template" wire:model="template" @class(['form-select', 'is-invalid' => $errors->has('template')])>
                                    <option value="">{{ __('No message') }}</option>
                                    @foreach ($templates as $item)
                                        <option value="{{ $item->code }}">{{ $item->label }}</option>
                                    @endforeach
                                </select>
                                @error('template') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                    <x-ui.button loading="send" icon="fa-paper-plane">{{ __('Send interest') }}</x-ui.button>
                </form>
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('Answering for a client') }}</h2>
            <p class="small">{{ __('Speak to the family before you accept. Accepting tells the member; you’ll arrange the next step with them.') }}</p>
            <p class="mb-0"><a href="{{ route('broker.profiles.show', ['code' => $client->code]) }}" wire:navigate>{{ __('Back to the profile') }}</a></p>
        </div>
    </div>
</div>
