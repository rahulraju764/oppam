{{--
    /notifications (PRD M08, template notifications.php: 3/6/3 — type filters | the list | ads).
    On phones the filters are a bottom sheet built in the component (like /profiles). Items come
    from App\Data\Notifications\NotificationItemData (display data; same-site links only).
--}}
<div x-data="{ sheet: false }" x-on:keydown.escape.window="sheet = false"
     x-effect="document.body.classList.toggle('search-sheet-open', sheet)"
     x-on:livewire:navigating.window="document.body.classList.remove('search-sheet-open')">
    <section class="dashboard-section notifications-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- Filters --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="search-sheet browse-rail" id="notification-filters" x-bind:class="{ 'is-open': sheet }" x-trap.noscroll="sheet"
                         role="region" aria-label="{{ __('Notification types') }}" x-bind:role="sheet ? 'dialog' : 'region'" x-bind:aria-modal="sheet ? 'true' : null">
                        <div class="search-sheet__head d-lg-none">
                            <h2 class="h5 mb-0">{{ __('Filter by') }}</h2>
                            <button type="button" class="search-sheet__close" x-on:click="sheet = false" aria-label="{{ __('Close') }}">
                                <i class="fa fa-times" aria-hidden="true"></i>
                            </button>
                        </div>
                        <nav class="side-bar" aria-label="{{ __('Notification types') }}">
                            <p class="sidebar-section-title">{{ __('Filter by') }}</p>
                            <button type="button" class="nav-link-custom nav-link-button @if ($current === null) active @endif"
                                    wire:click="$set('group', '')" x-on:click="sheet = false" aria-pressed="{{ $current === null ? 'true' : 'false' }}">
                                <span class="nav-link-label"><i class="fa fa-bell-o" aria-hidden="true"></i> {{ __('All') }}</span>
                            </button>
                            @foreach ($groups as $group)
                                <button type="button" class="nav-link-custom nav-link-button @if ($current === $group) active @endif"
                                        wire:click="$set('group', '{{ $group->value }}')" x-on:click="sheet = false" aria-pressed="{{ $current === $group ? 'true' : 'false' }}">
                                    <span class="nav-link-label"><i class="fa {{ $group->icon() }}" aria-hidden="true"></i> {{ $group->label() }}</span>
                                </button>
                            @endforeach
                            <div class="sidebar-divider"></div>
                            <div class="form-check nt-unread-only">
                                <input class="form-check-input" type="checkbox" id="nt-unread" name="unread" wire:model.live="unread">
                                <label class="form-check-label" for="nt-unread">{{ __('Unread only') }}</label>
                            </div>
                        </nav>
                    </div>
                </div>

                {{-- The list --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content">
                        <div class="content-header nt-header">
                            <div>
                                <h1>{{ __('Notifications') }}</h1>
                                <p aria-live="polite">{{ trans_choice(':count unread|:count unread', $unreadCount, ['count' => $unreadCount]) }}</p>
                            </div>
                            @if ($unreadCount > 0)
                                <button type="button" class="nt-mark-all" wire:click="markAllRead" wire:loading.attr="disabled" wire:target="markAllRead">{{ __('Mark all as read') }}</button>
                            @endif
                        </div>

                        <x-ui.button type="button" variant="outline" icon="fa-sliders" id="open-notification-filters" class="w-100 mb-3 d-lg-none"
                                     x-on:click="sheet = true" aria-controls="notification-filters" x-bind:aria-expanded="sheet">
                            {{ $current?->label() ?? __('All notifications') }}
                        </x-ui.button>

                        <div wire:loading.flex wire:target="group,unread" class="search-loading" aria-hidden="true">
                            <x-ui.skeleton shape="row" :count="3" class="w-100" />
                        </div>

                        <div wire:loading.remove wire:target="group,unread">
                            @if ($hasNew)
                                <div class="content-btn" role="status">
                                    <button type="button" class="view-btn" wire:click="reload" wire:loading.attr="disabled" wire:target="reload">
                                        <i class="fa fa-arrow-up" aria-hidden="true"></i> {{ __('New notifications — show') }}
                                    </button>
                                </div>
                            @endif

                            @foreach (['today' => __('Today'), 'yesterday' => __('Yesterday'), 'earlier' => __('Earlier')] as $day => $heading)
                                @if (! empty($byDay[$day]))
                                    <h2 class="nt-day">{{ $heading }}</h2>
                                    <ul class="nt-list">
                                        @foreach ($byDay[$day] as $item)
                                            <li @class(['nt-item', 'is-unread' => $item['unread']]) wire:key="nt-{{ $item['id'] }}">
                                                <span class="nt-icon {{ $item['iconClass'] }}"><i class="fa {{ $item['icon'] }}" aria-hidden="true"></i></span>
                                                <span class="nt-body">
                                                    {{-- No .stretched-link here (unlike the template): the row also holds the read / delete buttons. --}}
                                                    <a href="{{ $item['url'] }}" class="nt-text" wire:navigate>{{ $item['title'] }}</a>
                                                    @if ($item['body'] !== '')<span class="nt-text-body">{{ $item['body'] }}</span>@endif
                                                    <span class="nt-time text-muted-brand">{{ $item['when'] }}</span>
                                                </span>
                                                <span class="nt-actions">
                                                    @if ($item['unread'])
                                                        <span class="nt-dot" aria-hidden="true"></span>
                                                        <span class="visually-hidden">{{ __('Unread') }}</span>
                                                        <button type="button" class="nt-action" wire:click="markRead('{{ $item['id'] }}')" wire:loading.attr="disabled" wire:target="markRead('{{ $item['id'] }}')" aria-label="{{ __('Mark as read: :title', ['title' => $item['title']]) }}">
                                                            <i class="fa fa-check" aria-hidden="true"></i>
                                                        </button>
                                                    @endif
                                                    <button type="button" class="nt-action" wire:click="delete('{{ $item['id'] }}')" wire:loading.attr="disabled" wire:target="delete('{{ $item['id'] }}')" aria-label="{{ __('Delete: :title', ['title' => $item['title']]) }}">
                                                        <i class="fa fa-trash-o" aria-hidden="true"></i>
                                                    </button>
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            @endforeach

                            @if ($items === [])
                                <x-ui.empty-state icon="fa-bell-o" :title="$unread ? __('No unread notifications') : __('No notifications yet')"
                                                  :message="__('Likes, interests, messages and matches will show up here.')">
                                    <x-ui.button :href="route('member.matches')" wire:navigate>{{ __('See my matches') }}</x-ui.button>
                                </x-ui.empty-state>
                            @endif

                            @if ($cursor)
                                <div class="content-btn" x-intersect.margin.200px="$wire.loadMore()">
                                    <button type="button" class="view-btn" wire:click="loadMore" wire:loading.attr="disabled" wire:target="loadMore">
                                        <span wire:loading.remove wire:target="loadMore">{{ __('Load more') }}</span>
                                        <span wire:loading wire:target="loadMore">{{ __('Loading…') }}</span>
                                    </button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Ad rail --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>

    <div class="search-sheet__backdrop d-lg-none" x-show="sheet" x-cloak x-on:click="sheet = false"></div>
</div>
