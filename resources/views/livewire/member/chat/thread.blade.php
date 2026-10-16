{{--
    The open thread (PRD M07, template messages.php `.msg-panel`). Server-rendered messages carry
    their client id so the composer (Alpine chatThread, resources/js/alpine/chat.js) can drop its
    optimistic bubble once the stored message is on screen. role="log" + aria-live="polite": new
    messages are announced. Ticks have text for screen readers, not colour alone. Images are shown
    only through the authorised route (never a file URL).
--}}
<div class="msg-panel" data-chat-thread="{{ $code }}"
     x-data="chatThread(@js($maxLength), @js(['notSent' => __('Not sent. Please try again.'), 'tooLong' => __('Messages can be up to :count characters.', ['count' => $maxLength]), 'photo' => __('Photo'), 'tooBig' => __('Photos can be up to :count MB.', ['count' => $imageMaxMb])]), @js($support ? '' : 'chat.'.$threadId), @js($myCode), @js($imageMaxMb))"
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
                <p class="msg-status" aria-live="polite">
                    <span x-show="typing" x-cloak class="msg-typing">{{ __(':name is typing…', ['name' => $firstName]) }}</span>
                    <span x-show="! typing && $store.online && $store.online.has(@js($code))" x-cloak>{{ __('Online now') }}</span>
                    <span x-show="! typing && ! ($store.online && $store.online.has(@js($code)))">{{ $lastSeen ?? $code }}</span>
                </p>
            </div>
        </div>

        <div class="msg-panel-actions">
            @unless ($support)
                <button type="button" class="msg-icon-btn" wire:click="setMuted({{ $muted ? 'false' : 'true' }})" wire:loading.attr="disabled" wire:target="setMuted"
                        aria-pressed="{{ $muted ? 'true' : 'false' }}" aria-label="{{ $muted ? __('Unmute this conversation') : __('Mute this conversation') }}">
                    <i @class(['fa', 'fa-bell-slash' => $muted, 'fa-bell-o' => ! $muted]) aria-hidden="true"></i>
                </button>
            @endunless
            @if ($profileUrl)
                <a href="{{ $profileUrl }}" class="msg-panel-view" wire:navigate>{{ __('View profile') }}</a>
            @endif
        </div>
    </div>

    <p class="msg-reconnecting" role="status" x-show="$store.realtime && $store.realtime.status === 'reconnecting'" x-cloak>
        <span class="ui-spinner" aria-hidden="true"></span> {{ __('Reconnecting…') }}
    </p>

    {{-- THE THREAD --}}
    <div class="msg-thread-body-scroll" role="log" aria-live="polite" aria-label="{{ __('Conversation with :name', ['name' => $name]) }}"
         tabindex="0" x-ref="scroll" x-on:scroll.passive.throttle.250ms="nearTop()">

        @if ($support)
            <x-ui.alert type="info" class="msg-private">
                {{ __('Messages are private. Our team can read a conversation only through a recorded safety review.') }}
            </x-ui.alert>
        @endif

        @if ($hasEarlier)
            <div class="msg-earlier">
                <x-ui.button variant="ghost" size="sm" type="button" x-on:click="earlier()" loading="loadEarlier">{{ __('Load earlier messages') }}</x-ui.button>
            </div>
        @endif

        @foreach ($days as $day)
            <p class="msg-day"><span>{{ $day['label'] }}</span></p>

            @foreach ($day['messages'] as $row)
                @php($message = $row['message'])
                @if ($message->system)
                    <p class="msg-system" wire:key="msg-{{ $message->id }}" data-client-id="{{ $message->clientId }}" data-message-id="{{ $message->id }}">{{ $message->text }}</p>
                @else
                    <div @class(['msg-bubble', 'is-me' => $message->mine, 'is-grouped' => $row['grouped'], 'is-deleted' => $message->deleted, 'is-image' => $message->imageUrl !== null])
                         wire:key="msg-{{ $message->id }}" data-client-id="{{ $message->clientId }}" data-message-id="{{ $message->id }}">
                        @if ($message->imageUrl)
                            <a href="{{ route('member.chat.image', ['message' => $message->id, 'size' => 'original']) }}" target="_blank" rel="noopener" class="msg-image">
                                <img src="{{ $message->imageUrl }}" alt="{{ $message->mine ? __('Photo you sent') : __('Photo from :name', ['name' => $firstName]) }}" loading="lazy" decoding="async">
                            </a>
                        @else
                            <p class="msg-bubble-text">@if ($message->deleted)<i class="fa fa-ban" aria-hidden="true"></i> @endif{{ $message->text }}</p>
                        @endif
                        <p class="msg-bubble-time">
                            <span class="visually-hidden">{{ $message->mine ? __('You,') : $name.',' }}</span>
                            {{ $message->at->format('H:i') }}
                            @if ($message->tick && ! $message->deleted)
                                <span @class(['msg-tick', 'is-read' => $message->tick === 'read'])>
                                    <i class="fa fa-check" aria-hidden="true"></i>@if ($message->tick !== 'sent')<i class="fa fa-check" aria-hidden="true"></i>@endif
                                    <span class="visually-hidden">{{ $message->tickLabel() }}</span>
                                </span>
                            @endif
                        </p>
                        @if ($message->canUnsend)
                            <div class="msg-unsend" x-data="{ asking: false }">
                                <button type="button" class="msg-unsend-btn" x-show="! asking" x-on:click="asking = true">{{ __('Unsend') }}</button>
                                <span x-show="asking" x-cloak class="msg-unsend-ask">
                                    <button type="button" class="msg-unsend-btn" wire:click="unsend('{{ $message->id }}')" wire:loading.attr="disabled" wire:target="unsend">{{ __('Unsend for everyone') }}</button>
                                    <button type="button" class="msg-unsend-btn" x-on:click="asking = false">{{ __('Cancel') }}</button>
                                </span>
                            </div>
                        @endif
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
                        <button type="button" class="msg-retry" x-show="item.failed && item.retry" x-on:click="retry(item)">{{ __('Retry') }}</button>
                    </p>
                </div>
            </template>
        </div>
    </div>

    {{-- COMPOSER --}}
    @if ($state->canSend())
        @if ($icebreakers !== [])
            <div class="msg-icebreakers" role="group" aria-label="{{ __('Ready first messages') }}">
                @foreach ($icebreakers as $icebreakerCode => $icebreakerText)
                    <button type="button" class="msg-icebreaker" x-on:click="icebreaker(@js($icebreakerCode), @js($icebreakerText))">{{ $icebreakerText }}</button>
                @endforeach
            </div>
        @endif

        <form class="msg-composer" x-on:submit.prevent="submit()" novalidate>
            <div class="msg-composer-tools">
                <button type="button" class="msg-icon-btn" x-on:click="emojiOpen = ! emojiOpen" x-bind:aria-expanded="emojiOpen"
                        aria-controls="msg-emoji" aria-label="{{ __('Add an emoji') }}">
                    <i class="fa fa-smile-o" aria-hidden="true"></i>
                </button>
                @if ($canSendPhotos)
                    <label class="msg-icon-btn" for="msgPhoto" title="{{ __('Send a photo') }}">
                        <i class="fa fa-camera" aria-hidden="true"></i>
                        <span class="visually-hidden">{{ __('Send a photo') }}</span>
                    </label>
                    <input type="file" id="msgPhoto" name="photo" class="visually-hidden" accept="image/jpeg,image/png,image/webp"
                           x-ref="photo" x-on:change="sendPhoto($event.target)">
                @endif
            </div>

            <div class="msg-emoji" id="msg-emoji" x-show="emojiOpen" x-cloak x-on:click.outside="emojiOpen = false"
                 x-on:keydown.escape.stop="emojiOpen = false" role="group" aria-label="{{ __('Emoji') }}">
                <template x-for="emoji in emojis" :key="emoji">
                    <button type="button" class="msg-emoji-btn" x-text="emoji" x-on:click="addEmoji(emoji)" :aria-label="emoji"></button>
                </template>
            </div>

            <label for="msgText" class="visually-hidden">{{ __('Message to :name', ['name' => $name]) }}</label>
            <textarea class="form-control msg-composer-input" id="msgText" name="body" rows="2" maxlength="{{ $maxLength }}"
                      placeholder="{{ __('Write a message…') }}" x-model="body" x-ref="input" x-on:input="typed()"
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
