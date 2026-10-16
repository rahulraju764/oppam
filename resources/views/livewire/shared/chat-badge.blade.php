{{--
    The header's Messages link with the live unread count (PRD M07 Shared\ChatBadge). Desktop: an
    icon link beside the bell (same .nav-bell look); mobile: beside the mobile bell. The desktop one
    also turns "new message" signals into toasts (not for a thread open on screen) and feeds the
    browser tab title (resources/js/alpine/chat.js chatBadge). The component's root is a plain
    wrapper, never the link itself: re-rendering a component whose root is an <a wire:navigate>
    left a second link nested inside the first.
--}}
@php($label = trans_choice('Messages (:count unread)', $unread, ['count' => $unread]))
<span class="chat-badge" @if ($variant === 'desktop') x-data="chatBadge" x-effect="title($wire.unread)" x-on:chat-new-message.window="arrived($event.detail)" @endif>
    <a href="{{ route('member.messages') }}" wire:navigate aria-label="{{ $label }}" @if ($current) aria-current="page" @endif
       @class($variant === 'mobile' ? ['mobile-bell mobile-chat', 'is-current' => $current] : ['nav-bell nav-chat', 'is-current' => $current])>
        <i class="fa fa-envelope-o" aria-hidden="true"></i>
        @if ($unread > 0)
            <span class="{{ $variant === 'mobile' ? 'mobile-bell-count' : 'nav-bell-count' }}" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </a>
</span>
