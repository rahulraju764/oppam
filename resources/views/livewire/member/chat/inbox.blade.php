{{--
    The conversation list (PRD M07, template messages.php `.side-bar.msg-list`). Each row is ONE
    whole-row link (spans only inside — no nested controls); Archive / Unarchive is a sibling
    button. The visible unread number is aria-hidden and spelled out for screen readers (template).
--}}
<nav class="side-bar msg-list" aria-label="{{ __('Conversations') }}">

    <div class="msg-list-head">
        <h2>{{ __('Conversations') }}</h2>
        @if ($unreadThreads > 0)
            <span class="chip">{{ trans_choice(':count unread', $unreadThreads, ['count' => $unreadThreads]) }}</span>
        @endif
    </div>

    <div class="msg-list-tools">
        <label for="msg-search" class="visually-hidden">{{ __('Search conversations by name') }}</label>
        <input type="search" id="msg-search" name="search" class="form-control msg-list-search" autocomplete="off"
               placeholder="{{ __('Search by name') }}" maxlength="40" wire:model.live.debounce.400ms="search">

        <div class="msg-filters" role="group" aria-label="{{ __('Show') }}">
            @foreach ($filters as $key => $label)
                <button type="button" @class(['msg-filter', 'is-current' => $filter === $key])
                        aria-pressed="{{ $filter === $key ? 'true' : 'false' }}" wire:click="show('{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="msg-list-rows" wire:loading.class="is-loading" wire:target="show,search,showMore">
        @forelse ($items as $item)
            @php($isOpen = $item->code === $current)
            <div class="msg-thread-row" wire:key="msg-row-{{ $item->code }}">
                <a @class(['msg-thread', 'is-current' => $isOpen, 'is-unread' => $item->unread > 0])
                   href="{{ route('member.messages', ['with' => $item->code]) }}" wire:navigate
                   @if ($isOpen) aria-current="page" @endif data-chat-row="{{ $item->code }}">

                    <span class="msg-thread-img">
                        <img src="{{ $item->photoUrl }}" alt="" width="600" height="600" loading="lazy" decoding="async">
                        <x-ui.online-dot :code="$item->code" />
                    </span>

                    <span class="msg-thread-body">
                        <span class="msg-thread-top">
                            <strong class="msg-thread-name">{{ $item->name }}</strong>
                            <span class="msg-thread-time">{{ $item->timeLabel() }}</span>
                        </span>
                        <span class="msg-thread-last">
                            @if ($item->frozen)
                                <i class="fa fa-lock" aria-hidden="true"></i> {{ __('Closed for new messages') }}
                            @elseif ($item->preview === null)
                                {{ __('Messages are private') }}
                            @else
                                @if ($item->lastFromMe)<span class="visually-hidden">{{ __('You:') }} </span>@endif{{ $item->preview }}
                            @endif
                        </span>
                    </span>

                    @if ($item->unread > 0)
                        <span class="msg-thread-badge" aria-hidden="true">{{ $item->unread > 99 ? '99+' : $item->unread }}</span>
                        <span class="visually-hidden">{{ trans_choice(':count unread', $item->unread, ['count' => $item->unread]) }}</span>
                    @endif
                </a>

                <button type="button" class="msg-thread-archive"
                        wire:click="{{ $item->archived ? 'unarchive' : 'archive' }}('{{ $item->code }}')"
                        wire:loading.attr="disabled" wire:target="archive,unarchive"
                        aria-label="{{ $item->archived ? __('Move :name back to your inbox', ['name' => $item->name]) : __('Archive the conversation with :name', ['name' => $item->name]) }}">
                    <i @class(['fa', 'fa-inbox' => $item->archived, 'fa-archive' => ! $item->archived]) aria-hidden="true"></i>
                </button>
            </div>
        @empty
            <x-ui.empty-state icon="fa-comments-o" class="msg-list-empty"
                :title="match (true) {
                    $search !== '' => __('No conversation with that name'),
                    $filter === 'unread' => __('All caught up'),
                    $filter === 'archived' => __('Nothing archived'),
                    default => __('No conversations yet'),
                }"
                :message="$filter === 'all' && $search === '' ? __('When you and another member accept an interest, your conversation appears here.') : null">
                @if ($filter === 'all' && $search === '')
                    <x-ui.button variant="outline" size="sm" :href="route('member.interests')" wire:navigate>{{ __('See your interests') }}</x-ui.button>
                @endif
            </x-ui.empty-state>
        @endforelse

        @if ($more)
            <x-ui.button variant="ghost" size="sm" type="button" class="msg-list-more" wire:click="showMore" loading="showMore">{{ __('Show more') }}</x-ui.button>
        @endif
    </div>
</nav>
