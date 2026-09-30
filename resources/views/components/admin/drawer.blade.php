{{--
    <x-admin.drawer> — side panel for record details (PRD §11.0). Alpine; Esc and the close button
    close it; focus is trapped while open. Open with $dispatch('open-drawer', { name: '…' }).
    name, title (required).
--}}
@props(['name', 'title'])

<div x-data="{ open: false }"
     x-on:open-drawer.window="if ($event.detail.name === @js($name)) open = true"
     x-on:close-drawer.window="if ($event.detail.name === @js($name)) open = false"
     x-on:keydown.escape.window="open = false">
    <div class="admin-drawer" x-show="open" x-cloak x-trap.noscroll="open" role="dialog" aria-modal="true" aria-labelledby="drawer-{{ $name }}-title">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="h5 m-0" id="drawer-{{ $name }}-title">{{ $title }}</h2>
            <button type="button" class="ui-modal__close" x-on:click="open = false" aria-label="{{ __('Close') }}">
                <i class="fa fa-times" aria-hidden="true"></i>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
