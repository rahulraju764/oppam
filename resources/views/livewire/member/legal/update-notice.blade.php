{{-- "We updated our terms" (A08): a newer, non-material legal version the member hasn't seen yet. --}}
<div>
    @if ($labels !== [])
        <div class="container">
            <x-ui.alert type="info">
                {{ __('We’ve updated our') }}
                @foreach ($labels as $kind)
                    <a href="{{ route($kind->route()) }}">{{ $kind->label() }}</a>@if (! $loop->last) {{ __('and') }} @endif
                @endforeach.
                <button type="button" class="btn btn-link p-0 align-baseline" wire:click="dismiss" wire:loading.attr="disabled" wire:target="dismiss">{{ __('Got it') }}</button>
            </x-ui.alert>
        </div>
    @endif
</div>
