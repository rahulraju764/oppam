{{--
    <x-admin.confirm-modal> — typed-reason confirmation for destructive admin actions (PRD §11.0).
    The reason is bound to a Livewire property (reasonModel) and the confirm button calls the
    Livewire action; the server validates the reason again. Open with
    $dispatch('open-modal', { name: '…' }).

    name, title, action (Livewire method), reasonModel (Livewire property), confirmLabel, danger
--}}
@props(['name', 'title', 'action', 'reasonModel' => 'reason', 'confirmLabel' => null, 'danger' => true])

<x-ui.modal :name="$name" :title="$title">
    {{ $slot }}

    <div class="mt-3">
        <x-ui.textarea :label="__('Reason (recorded in the audit log)')" :name="$name.'-reason'" :id="$name.'-reason'"
                       wire:model="{{ $reasonModel }}" :error="$reasonModel" rows="3" required />
    </div>

    <x-slot:footer>
        <x-ui.button variant="ghost" type="button" x-on:click="open = false">{{ __('Cancel') }}</x-ui.button>
        <x-ui.button :variant="$danger ? 'danger' : 'primary'" type="button" wire:click="{{ $action }}" :loading="$action">
            {{ $confirmLabel ?? __('Confirm') }}
        </x-ui.button>
    </x-slot:footer>
</x-ui.modal>
