{{--
    The open thread (PRD M07, template messages.php `.msg-panel`). Server-rendered messages carry
    their client id so the composer (Alpine chatThread, resources/js/alpine/chat.js) can drop its
    optimistic bubble once the stored message is on screen. role="log" + aria-live="polite": new
    messages are announced. Ticks have text for screen readers, not colour alone.
--}}
<div class="msg-panel" data-chat-thread="{{ $code }}"
     x-data="chatThread(@js($maxLength), @js(['notSent' => __('Not sent. Please try again.'), 'tooLong' => __('Messages can be up to :count characters.', ['count' => $maxLength])]), @js($support ? '' : 'chat.'.$threadId), @js($myCode))"
     x-on:realtime-fallback.window="startPolling()">

    {{-- THREAD HEADER — the other member and the way to their profile (single-profile is the only profile view). --}}
    <div class="msg-panel-head">
        <div class="msg-panel-who">
            <a href="{{ route('member.messages') }}" class="msg-back d-lg-none" wire:navigate aria-label="{{ __('Back to conversations') }}">
                <i class="fa fa-arrow-left" aria-hidden="true"></i>
            </a>
            <span class="msg-panel-avatar">
                <img src="{{ $photoUrl }}" alt="" width="600" height="600" loading="lazy" decoding="async">
                <x-ui.online-dot :code="$code" />
            </span>
            <div>
                <h2>{{ $name }}</h2>
                <p class="text-muted-brand">{{ $code }}</p>
            </div>
        </div>

        @if ($profileUrl)
            <a href="{{ $profileUrl }}" class="msg-panel-view" wire:navigate>{{ __('View profile') }}</a>
        @endif
    </div>

    <p class="msg-reconnecting" role="status" x-show="$store.realtime && $store.realtime.status === 'reconnecting'" x-cloak>
        <span class="ui-spinner" aria-hidden="true"></span> {{ __('Reconnecting…') }}
    </p>

    {{-- THE THREAD --}}
    <div class="msg-thread-body-scroll" role="log" aria-live="polite" aria-label="{{ __('Conversation with :name', ['name' => $name]) }}"
         tabindex="0" x-ref="scroll">

        @if ($support)
            <x-ui.alert type="info" class="msg-private">
                {{ __('Messages are private. Our team can read a conversation only through a recorded safety review.') }}
            </x-ui.alert>
        @endif

        @foreach ($days as $day)
            <p class="msg-day"><span>{{ $day['label'] }}</span></p>

            @foreach ($day['messages'] as $row)
                @php($message = $row['message'])
                @if ($message->system)
                    <p class="msg-system" wire:key="msg-{{ $message->id }}" data-client-id="{{ $message->clientId }}">{{ $message->text }}</p>
                @else
                    <div @class(['msg-bubble', 'is-me' => $message->mine, 'is-grouped' => $row['grouped']])
                         wire:key="msg-{{ $message->id }}" data-client-id="{{ $message->clientId }}">
                        <p class="msg-bubble-text">{{ $message->text }}</p>
                        <p class="msg-bubble-time">
                            <span class="visually-hidden">{{ $message->mine ? __('You,') : $name.',' }}</span>
                            {{ $message->at->format('H:i') }}
                            @if ($message->tick)
                                <span @class(['msg-tick', 'is-read' => $message->tick === 'read'])>
                                    <i class="fa fa-check" aria-hidden="true"></i>@if ($message->tick !== 'sent')<i class="fa fa-check" aria-hidden="true"></i>@endif
                                    <span class="visually-hidden">{{ $message->tickLabel() }}</span>
                                </span>
                            @endif
                        </p>
                    </div>
                @endif
            @endforeach
        @endforeach

        @if (! $support && $days === [])
            <p class="msg-system">{{ __('Say hello — this is the start of your conversation.') }}</p>
        @endif

        {{-- Alpine only, never touched by Livewire's re-render: messages drawn from the live payload
             the moment they arrive, and the member's own optimistic bubbles. Neither carries
             data-client-id, so the stored message replacing it is recognised. --}}
        <div class="msg-pending" wire:ignore>
            <template x-for="item in live" :key="'live-' + item.clientId">
                <div class="msg-bubble" :class="{ 'is-me': item.mine }">
                    <p class="msg-bubble-text" x-text="item.text"></p>
                    <p class="msg-bubble-time" x-text="item.time"></p>
                </div>
            </template>
            <template x-for="item in pending" :key="item.clientId">
                <div class="msg-bubble is-me is-pending" :class="{ 'is-failed': item.failed }">
                    <p class="msg-bubble-text" x-text="item.text"></p>
                    <p class="msg-bubble-time">
                        <span x-show="! item.failed">{{ __('Sending…') }}</span>
                        <span x-show="item.failed" x-text="item.error"></span>
                        <button type="button" class="msg-retry" x-show="item.failed" x-on:click="retry(item)">{{ __('Retry') }}</button>
                    </p>
                </div>
            </template>
        </div>
    </div>

    {{-- COMPOSER --}}
    @if ($state->canSend())
        <form class="msg-composer" x-on:submit.prevent="submit()" novalidate>
            <label for="msgText" class="visually-hidden">{{ __('Message to :name', ['name' => $name]) }}</label>
            <textarea class="form-control msg-composer-input" id="msgText" name="body" rows="2" maxlength="{{ $maxLength }}"
                      placeholder="{{ __('Write a message…') }}" x-model="body" x-ref="input"
                      x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); submit() }"></textarea>

            <button type="submit" class="btn msg-composer-send" x-bind:disabled="body.trim() === ''">
                <i class="fa fa-paper-plane" aria-hidden="true"></i>
                <span class="msg-composer-send-label">{{ __('Send') }}</span>
            </button>
        </form>
        @if ($freeLeft !== null)
            <p class="msg-composer-hint">
                {{ trans_choice('You have :count free reply left in this conversation.|You have :count free replies left in this conversation.', $freeLeft, ['count' => $freeLeft]) }}
                <a href="{{ $plansUrl }}" wire:navigate>{{ __('Upgrade to chat freely') }}</a>
            </p>
        @endif
    @elseif (! $support)
        <div class="msg-composer is-locked" role="status">
            <p class="msg-locked-text">
                <i class="fa fa-lock" aria-hidden="true"></i>
                {{ $state->message() }}
            </p>
            @if ($upgradable)
                <x-ui.button size="sm" :href="$plansUrl" wire:navigate>{{ __('Upgrade to chat') }}</x-ui.button>
            @endif
        </div>
    @endif
</div>
