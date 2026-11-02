@if ($blockKey !== null)
    @foreach ($blockKey->fields() as $field => $max)
        <div class="col-12">
            @if ($max > 120)
                <x-ui.textarea :label="\App\Enums\HomeBlockKey::fieldLabel($field)" :name="$field" :id="'block-'.$field" wire:model="fields.content.{{ $field }}" rows="2" :maxlength="$max" required />
            @else
                <x-ui.input :label="\App\Enums\HomeBlockKey::fieldLabel($field)" :name="$field" :id="'block-'.$field" wire:model="fields.content.{{ $field }}" :maxlength="$max" required />
            @endif
        </div>
    @endforeach
    <div class="col-6 col-md-4"><x-ui.input :label="__('Order')" name="sort_order" id="block-order" inputmode="numeric" wire:model="fields.sort_order" required /></div>
@endif
