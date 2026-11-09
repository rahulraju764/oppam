{{--
    A14 announcement banner (P8.3a) on member pages: the live announcements for this member, each
    with an optional button and (if dismissible) a close button. Escaped text only.
--}}
<div class="announcement-stack" aria-live="polite">
    @foreach ($announcements as $announcement)
        <div class="announcement-bar" role="region" aria-label="{{ __('Announcement') }}" wire:key="ann-{{ $announcement->code }}" dusk="announcement-{{ $announcement->code }}">
            <div class="container is-chrome announcement-bar__inner">
                <i class="fa fa-bullhorn announcement-bar__icon" aria-hidden="true"></i>
                <p class="announcement-bar__text">
                    <strong>{{ $announcement->title }}</strong>
                    <span>{{ $announcement->body }}</span>
                </p>
                @if ($announcement->action_url && $announcement->action_text)
                    <a class="announcement-bar__action" href="{{ $announcement->action_url }}"
                       @if (str_starts_with($announcement->action_url, '/')) wire:navigate @else rel="noopener noreferrer" target="_blank" @endif>
                        {{ $announcement->action_text }}
                    </a>
                @endif
                @if ($announcement->is_dismissible)
                    <button type="button" class="announcement-bar__close" wire:click="dismiss('{{ $announcement->code }}')"
                            wire:loading.attr="disabled" wire:target="dismiss" aria-label="{{ __('Dismiss announcement: :title', ['title' => $announcement->title]) }}">
                        <i class="fa fa-times" aria-hidden="true"></i>
                    </button>
                @endif
            </div>
        </div>
    @endforeach
</div>
