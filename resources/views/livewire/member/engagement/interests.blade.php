{{--
    /interests (PRD M06, template interest.php: 3/6/3 — filters | the list | ads). The filter rail
    is desktop-only, as in the template; on phones the same filters are the Interests sheet of the
    bottom tab bar (layouts/partials/mobile-tabbar). Received + pending rows carry Accept / Decline
    (template `profile-row` "respond" actions) and a tick for "decline selected"; sent + pending
    rows carry Withdraw; settled rows show their status chip; accepted rows get the Chat button.
--}}
@php
    $boxLabels = ['received' => __('Interests Received'), 'sent' => __('Interests Sent')];
    $title = $requests ? __('Photo & Horoscope Requests') : $boxLabels[$box];
    $pendingReceived = ! $requests && $box === 'received' && $status === 'pending';
@endphp
<div>
    <section class="dashboard-section interest-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Filters (desktop) --}}
                <div class="col-lg-3 d-none d-lg-block">
                    <nav class="side-bar" aria-label="{{ __('Interest filters') }}">
                        @foreach ($boxLabels as $boxKey => $boxLabel)
                            <p class="sidebar-section-title">{{ $boxLabel }}</p>
                            @foreach ($statusLabels as $statusKey => $statusLabel)
                                @php($current = ! $requests && $box === $boxKey && $status === $statusKey)
                                <button type="button" @class(['nav-link-custom nav-link-button', 'active' => $current])
                                        wire:click="show('{{ $boxKey }}', '{{ $statusKey }}')" aria-pressed="{{ $current ? 'true' : 'false' }}">
                                    <span class="nav-link-label">{{ $statusLabel }}</span>
                                    <span class="nav-count">{{ number_format($counts[$boxKey][$statusKey]) }}</span>
                                </button>
                            @endforeach
                            <div class="sidebar-divider"></div>
                        @endforeach

                        <p class="sidebar-section-title">{{ __('Requests') }}</p>
                        <button type="button" @class(['nav-link-custom nav-link-button', 'active' => $requests])
                                wire:click="show('requests')" aria-pressed="{{ $requests ? 'true' : 'false' }}">
                            <span class="nav-link-label">{{ __('Photo & horoscope') }}</span>
                            <span class="nav-count">{{ number_format($requestCount) }}</span>
                        </button>
                    </nav>
                </div>

                {{-- The list --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content match-results">
                        <section class="home-content">
                            <div class="content-header">
                                <div class="content-title">
                                    <h1>{{ $title }}</h1>
                                    <p>
                                        @if ($requests)
                                            {{ trans_choice(':count member is waiting for your answer|:count members are waiting for your answer', $requestCount, ['count' => number_format($requestCount)]) }}
                                        @else
                                            {{ $statusLabels[$status] }} · {{ number_format($counts[$box][$status]) }}
                                            @if ($pendingReceived && $counts[$box][$status] > 0) — {{ __('reply before they expire') }} @endif
                                        @endif
                                    </p>
                                </div>
                                @if ($pendingReceived && $rows !== [])
                                    <div x-data>
                                        <x-ui.button type="button" variant="outline" size="sm" icon="fa-times" wire:click="declineSelected" loading="declineSelected"
                                                     x-bind:disabled="$wire.selected.length === 0">{{ __('Decline selected') }}</x-ui.button>
                                    </div>
                                @endif
                            </div>

                            <div wire:loading.flex wire:target="show" class="search-loading" aria-hidden="true">
                                <x-ui.skeleton shape="row" :count="3" class="w-100" />
                            </div>

                            <div class="profile-grid" wire:loading.remove wire:target="show">
                                @forelse ($rows as $row)
                                    @php($code = $row['card']->code)
                                    <x-profile.row :profile="$row['card']" :hide-interest="$requests || $row['status'] !== \App\Enums\InterestStatus::Accepted"
                                                   wire:key="interest-{{ $box }}-{{ $code }}-{{ $requests ? $row['type']->value : $row['status']->value }}">
                                        <x-slot:actions>
                                            @if ($requests)
                                                <span class="visit-time">{{ __(':what requested :when', ['what' => $row['type']->label(), 'when' => $row['when']]) }}</span>
                                                <button type="button" class="btn-decline" wire:click="answerRequest('{{ $code }}', '{{ $row['type']->value }}', false)"
                                                        wire:loading.attr="disabled" wire:target="answerRequest('{{ $code }}', '{{ $row['type']->value }}', false)"
                                                        aria-label="{{ __('Decline the request from :name', ['name' => $row['card']->name]) }}">
                                                    <i class="fa fa-times" aria-hidden="true"></i>{{ __('Decline') }}</button>
                                                <button type="button" class="btn-accept" wire:click="answerRequest('{{ $code }}', '{{ $row['type']->value }}', true)"
                                                        wire:loading.attr="disabled" wire:target="answerRequest('{{ $code }}', '{{ $row['type']->value }}', true)"
                                                        aria-label="{{ __('Approve the request from :name', ['name' => $row['card']->name]) }}">
                                                    <i class="fa fa-check" aria-hidden="true"></i>{{ __('Approve') }}</button>
                                            @elseif ($row['status'] === \App\Enums\InterestStatus::Pending && $box === 'received')
                                                <div class="form-check interest-pick">
                                                    <input class="form-check-input" type="checkbox" id="interest-pick-{{ $code }}" name="selected[]" value="{{ $code }}" wire:model="selected">
                                                    <label class="form-check-label" for="interest-pick-{{ $code }}">{{ __('Select') }}<span class="visually-hidden"> {{ $row['card']->name }}</span></label>
                                                </div>
                                                <button type="button" class="btn-decline" wire:click="decline('{{ $code }}')" wire:loading.attr="disabled" wire:target="decline('{{ $code }}')"
                                                        aria-label="{{ __('Decline :name', ['name' => $row['card']->name]) }}">
                                                    <i class="fa fa-times" aria-hidden="true"></i>{{ __('Decline') }}</button>
                                                <button type="button" class="btn-accept" wire:click="accept('{{ $code }}')" wire:loading.attr="disabled" wire:target="accept('{{ $code }}')"
                                                        aria-label="{{ __('Accept :name', ['name' => $row['card']->name]) }}">
                                                    <i class="fa fa-check" aria-hidden="true"></i>{{ __('Accept') }}</button>
                                            @elseif ($row['status'] === \App\Enums\InterestStatus::Pending)
                                                <x-ui.badge :variant="$row['status']->badge()">{{ $row['status']->label() }}</x-ui.badge>
                                                <button type="button" class="btn-decline" wire:click="withdraw('{{ $code }}')" wire:loading.attr="disabled" wire:target="withdraw('{{ $code }}')"
                                                        aria-label="{{ __('Withdraw the interest sent to :name', ['name' => $row['card']->name]) }}">
                                                    <i class="fa fa-undo" aria-hidden="true"></i>{{ __('Withdraw') }}</button>
                                            @else
                                                <x-ui.badge :variant="$row['status']->badge()">{{ $row['status']->label() }}</x-ui.badge>
                                            @endif

                                            @unless ($requests)
                                                <span class="visit-time"><i class="fa fa-clock-o" aria-hidden="true"></i> {{ $row['when'] }}</span>
                                                @if ($row['message'] !== '')
                                                    <p class="interest-note">“{{ $row['message'] }}”</p>
                                                @endif
                                            @endunless
                                        </x-slot:actions>
                                    </x-profile.row>
                                @empty
                                    <x-ui.empty-state icon="fa-envelope-o"
                                                      :title="$requests ? __('No requests waiting') : __('No interests here yet')"
                                                      :message="$requests ? __('When someone asks to see your photos or horoscope, you decide here.') : ($box === 'sent' ? __('Send an interest from a profile you like — it is the first step to connect.') : __('A complete profile with photos gets more interests.'))">
                                        <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See my matches') }}</x-ui.button>
                                    </x-ui.empty-state>
                                @endforelse
                            </div>
                        </section>
                    </div>

                    <x-ui.pagination :paginator="$page" :label="__('Interest list pages')" />
                </div>

                {{-- Ad rail --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
