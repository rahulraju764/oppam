<div class="ui-card">
    <h2 class="h6">{{ __('Internal notes') }}</h2>
    <p class="small text-muted-brand">{{ __('Visible to admins only, never to the member. Notes can\'t be edited or deleted — add a new note to correct one.') }}</p>

    @if ($canAdd)
        <x-ui.textarea :label="__('New note')" name="noteBody" wire:model="noteBody" rows="3" />
        <x-ui.button size="sm" type="button" wire:click="add" loading="add" icon="fa-plus">{{ __('Add note') }}</x-ui.button>
    @endif

    @if ($notes->isEmpty())
        <x-ui.empty-state icon="fa-sticky-note-o" :title="__('No notes yet')" />
    @else
        <ul class="list-unstyled mt-4 admin-notes">
            @foreach ($notes as $note)
                <li wire:key="note-{{ $note->id }}">
                    <p class="mb-1">{{ $note->body }}</p>
                    <p class="small text-muted-brand mb-0">{{ $note->admin_label ?? __('Unknown admin') }} · {{ $note->created_at->timezone($timezone)->format('d M Y, g:i a') }}</p>
                </li>
            @endforeach
        </ul>
    @endif
</div>
