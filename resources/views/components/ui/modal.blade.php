{{--
    <x-ui.modal> — Alpine dialog: focus trapped (x-trap), Esc / backdrop / close button close it,
    focus returns to the control that opened it, role="dialog" + aria-modal + aria-labelledby.
    Open with $dispatch('open-modal', { name: '…' }) in Alpine, or from Livewire:
    $this->dispatch('open-modal', name: '…'). Close with 'close-modal' the same way.
    name, title (required) · slots: default (body), footer.
--}}
@props(['name', 'title'])

@php($titleId = 'modal-'.$name.'-title')

<div x-data="{ open: false, opener: null }"
     x-on:open-modal.window="if ($event.detail.name === @js($name)) { opener = document.activeElement; open = true }"
     x-on:close-modal.window="if ($event.detail.name === @js($name)) { open = false }"
     x-on:keydown.escape.window="open = false"
     x-effect="if (! open && opener) { opener.focus(); opener = null }">
    <div class="ui-modal" x-show="open" x-cloak x-on:click.self="open = false">
        <div {{ $attributes->class('ui-modal__panel') }} role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}" x-trap.noscroll="open">
            <div class="ui-modal__head">
                <h2 class="ui-modal__title" id="{{ $titleId }}">{{ $title }}</h2>
                <button type="button" class="ui-modal__close" x-on:click="open = false" aria-label="{{ __('Close') }}">
                    <i class="fa fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="ui-modal__body">{{ $slot }}</div>
            @isset($footer)
                <div class="ui-modal__foot">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
