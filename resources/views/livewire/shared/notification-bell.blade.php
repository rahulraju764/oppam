{{--
    The header bell (PRD M08). Desktop: a disclosure button with the live count; opening it loads the
    latest notifications (lazy), with "Mark all as read" and a link to /notifications. Mobile: a link
    to /notifications with the live count. The unread count also prefixes the browser tab title.
    In polling-fallback mode (no socket, flag on) the count is re-read every 30 s.
--}}
@php($label = trans_choice('Notifications (:count unread)', $unread, ['count' => $unread]))
@if ($variant === 'mobile')
    <a href="{{ route('member.notifications') }}" @class(['mobile-bell', 'is-current' => request()->routeIs('member.notifications')])
       aria-label="{{ $label }}" wire:navigate
       x-data="{ polling: null }" x-on:realtime-fallback.window="polling ??= setInterval(() => $wire.refreshBell(), 30000)"
       x-on:livewire:navigating.window="clearInterval(polling)">
        <i class="fa fa-bell-o" aria-hidden="true"></i>
        @if ($unread > 0)
            <span class="mobile-bell-count" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </a>
@else
    {{-- `open` comes from the wrapping <li x-data> in header-member-nav (survives re-renders). --}}
    <div class="nt-bell__inner"
         x-data="{ polling: null, base: document.title.replace(/^\(\d+\+?\) /, '') }"
         x-effect="document.title = ($wire.unread > 0 ? '(' + ($wire.unread > 99 ? '99+' : $wire.unread) + ') ' : '') + base"
         x-on:realtime-fallback.window="polling ??= setInterval(() => $wire.refreshBell(), 30000)"
         x-on:livewire:navigating.window="clearInterval(polling)">
        <button type="button" class="nav-bell" aria-label="{{ $label }}" aria-haspopup="true" aria-controls="nt-dropdown"
                x-bind:aria-expanded="open" x-on:click="open = ! open; if (open) $wire.open()">
            <i class="fa fa-bell-o" aria-hidden="true"></i>
            @if ($unread > 0)
                <span class="nav-bell-count" aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span>
            @endif
        </button>

        <div class="nt-dropdown" id="nt-dropdown" x-show="open" x-cloak role="region" aria-label="{{ __('Latest notifications') }}">
            <div class="nt-dropdown__head">
                <strong>{{ __('Notifications') }}</strong>
                @if ($unread > 0)
                    <button type="button" class="nt-mark-all" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead">{{ __('Mark all as read') }}</button>
                @endif
            </div>

            @if ($items === null)
                <div class="nt-dropdown__loading" aria-hidden="true"><x-ui.skeleton shape="row" :count="3" class="w-100" /></div>
                <span class="visually-hidden" role="status">{{ __('Loading…') }}</span>
            @elseif ($items === [])
                <p class="nt-dropdown__empty">{{ __('You’re all caught up.') }}</p>
            @else
                <ul class="nt-list nt-list--compact">
                    @foreach ($items as $item)
                        <li @class(['nt-item', 'is-unread' => $item['unread']]) wire:key="bell-{{ $item['id'] }}">
                            <span class="nt-icon {{ $item['iconClass'] }}"><i class="fa {{ $item['icon'] }}" aria-hidden="true"></i></span>
                            <span class="nt-body">
                                <a href="{{ $item['url'] }}" class="nt-text stretched-link" wire:navigate>{{ $item['title'] }}</a>
                                <span class="nt-time text-muted-brand">{{ $item['when'] }}</span>
                            </span>
                            @if ($item['unread'])
                                <span class="nt-dot" aria-hidden="true"></span>
                                <span class="visually-hidden">{{ __('Unread') }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            <a href="{{ route('member.notifications') }}" class="nt-dropdown__all" wire:navigate>{{ __('See all notifications') }}</a>
        </div>
    </div>
@endif
