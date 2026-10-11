{{--
    <x-engagement.interest-button> — where the member stands with a profile, and the next step
    (PRD M06 `Shared\InterestButton`, F02):
      none       "Send Interest"  → opens the Send Interest dialog (Shared\InterestComposer)
      sent       "Interest sent"  (withdraw it on /interests)
      received   "Respond"        → /interests
      connected  "Chat"           → the conversation (the chat screens arrive with P4.1)
    Plain Blade + Alpine (`interestButton`, resources/js/alpine/engagement.js): the state switches
    on the window event `interest-changed` { code, state } — sent by the dialog in this tab and by
    the live `.interest.changed` from the other member — so both sides update without a reload
    (M06 acceptance). It is display only: every rule is enforced by the Actions.

    code   the profile code        state  Connections::NONE | SENT | RECEIVED | CONNECTED
--}}
@props(['code', 'state' => 'none'])

@php
    // Explicit allow-list: never trust a state string into the markup.
    $state = in_array($state, ['none', 'sent', 'received', 'connected'], true) ? $state : 'none';
    $chatUrl = \Illuminate\Support\Facades\Route::has('member.messages') ? route('member.messages', ['with' => $code]) : null;
@endphp

{{-- wire:ignore: the state lives in Alpine once rendered; a re-render of the page around it (load
     more, a refresh) must not morph it back to what the server knew when the row was first drawn. --}}
<span {{ $attributes->class('engage engage--interest') }} wire:ignore x-data="interestButton(@js($state), @js($code), @js($chatUrl))"
      data-chat-soon="{{ __('You are connected. Chat opens here very soon.') }}">
    <button type="button" class="send-int interest-btn" x-show="state === 'none'" x-on:click="compose()" @if ($state !== 'none') x-cloak @endif>
        <i class="fa fa-envelope" aria-hidden="true"></i>{{ __('Send Interest') }}
    </button>

    <span class="chip chip--warning interest-chip" x-show="state === 'sent'" @if ($state !== 'sent') x-cloak @endif>
        <i class="fa fa-check" aria-hidden="true"></i> {{ __('Interest sent') }}
    </span>

    <a href="{{ route('member.interests') }}" class="send-int interest-btn" x-show="state === 'received'" wire:navigate @if ($state !== 'received') x-cloak @endif>
        <i class="fa fa-reply" aria-hidden="true"></i>{{ __('Respond to interest') }}
    </a>

    <button type="button" class="send-int interest-btn chat-btn" x-show="state === 'connected'" x-on:click="chat()" @if ($state !== 'connected') x-cloak @endif>
        <i class="fa fa-comments" aria-hidden="true"></i>{{ __('Chat') }}
    </button>
</span>
