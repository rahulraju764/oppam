{{--
    A13 break-glass viewer: read only, watermarked (admin, time, session), time-limited. The
    transcript leaves the page when the session ends; each page read is audited server-side.
--}}
<div wire:poll.60s>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Break-glass read') }}</h1>
            <p>
                <a href="{{ route('admin.chat.break-glass') }}" wire:navigate><i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back to break-glass') }}</a>
            </p>
        </div>
        @if ($active)
            <div class="admin-break-glass__clock" x-data="{ left: {{ $secondsLeft }} }"
                 x-init="const timer = setInterval(() => { left = Math.max(0, left - 1); if (left === 0) { clearInterval(timer); $wire.$refresh(); } }, 1000)">
                <span class="small">{{ __('Time left') }}</span>
                <strong x-text="`${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`" aria-live="off">{{ intdiv($secondsLeft, 60) }}:{{ str_pad((string) ($secondsLeft % 60), 2, '0', STR_PAD_LEFT) }}</strong>
                <x-ui.button size="sm" variant="danger" type="button" icon="fa-lock" wire:click="end" loading="end">{{ __('End now') }}</x-ui.button>
            </div>
        @endif
    </div>

    <div class="ui-card mb-4">
        <dl class="mod-facts">
            <dt>{{ __('Members') }}</dt><dd>{{ $members }}</dd>
            <dt>{{ __('Reference') }}</dt><dd>{{ $grant->reference_type->label() }} · {{ $grant->reference }}</dd>
            <dt>{{ __('Reason') }}</dt><dd>{{ $grant->reason }}</dd>
            <dt>{{ __('Opened') }}</dt><dd>{{ $grant->created_at?->setTimezone(config('oppam.display_timezone'))->format('d M Y, H:i') }}</dd>
        </dl>
    </div>

    @if (! $active)
        <x-ui.empty-state icon="fa-lock" :title="__('This session has ended')"
                          :message="__('The conversation is no longer shown. Open a new break-glass session, with its own reason, to read it again.')" />
    @elseif ($page === null || $page->messages === [])
        <x-ui.empty-state icon="fa-comments-o" :title="__('No messages in this conversation')" />
    @else
        <div class="admin-break-glass">
            <div class="admin-break-glass__watermark" aria-hidden="true">
                @for ($i = 0; $i < 24; $i++)<span>{{ $watermark }}</span>@endfor
            </div>

            @if ($page->hasEarlier)
                <div class="text-center mb-3">
                    <x-ui.button size="sm" variant="outline" type="button" icon="fa-history" wire:click="loadEarlier" loading="loadEarlier">{{ __('Load earlier messages') }}</x-ui.button>
                </div>
            @endif

            <ol class="admin-chat-context" aria-label="{{ __('The conversation, oldest first') }}">
                @foreach ($page->messages as $row)
                    <li @class(['admin-chat-context__item', 'is-flagged' => $row->isFlagged, 'is-gone' => $row->gone]) wire:key="bg-{{ $row->id }}">
                        <span class="admin-chat-context__meta">
                            {{ $row->senderLabel }} · {{ $row->at->format('d M Y, H:i') }}
                            @if ($row->isFlagged)<x-ui.badge variant="warning">{{ __('Flagged') }}</x-ui.badge>@endif
                        </span>
                        @if ($row->isImage)
                            <img src="{{ route('admin.chat.break-glass.image', ['grant' => $grant->id, 'message' => $row->id]) }}" alt="{{ __('Photo sent in the conversation') }}" class="admin-chat-context__img" loading="lazy">
                        @else
                            <span class="admin-chat-context__text">{{ $row->text }}</span>
                        @endif
                    </li>
                @endforeach
            </ol>
        </div>
    @endif
</div>
