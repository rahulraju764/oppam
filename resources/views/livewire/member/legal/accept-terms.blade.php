{{--
    /legal/accept (A08 forced re-acceptance). In the legal pages' frame (.privacy-section,
    .container.is-readable). Each changed document links to its full text and shows what changed.
--}}
<div>
    <section class="privacy-section">
        <div class="container is-readable">
            <h1 class="t-head">{{ __('We’ve updated our terms') }}</h1>
            <p class="p-intro">{{ __('Please read the changes below. You need to agree to them to keep using Oppam Matrimony.') }}</p>

            <ul class="p-main legal-list">
                @foreach ($kinds as $kind)
                    @php($document = $documents[$kind->value] ?? null)
                    <li>
                        <a href="{{ route($kind->route()) }}" target="_blank" rel="noopener">{{ $kind->label() }}</a>
                        @if ($document)
                            — {{ __('version :n, effective from :date', ['n' => $document->version, 'date' => $document->effective_on->format('j F Y')]) }}
                            @if ($document->summary)
                                <span class="d-block">{{ $document->summary }}</span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>

            <form wire:submit="accept" class="mt-4">
                <x-ui.checkbox name="agree" id="legal-agree" wire:model="agree">
                    {{ __('I have read and agree to the updated documents above.') }}
                </x-ui.checkbox>
                <div class="d-flex flex-wrap gap-3 mt-3">
                    <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="accept" dusk="legal-accept">
                        <span wire:loading.remove wire:target="accept">{{ __('Agree and continue') }}</span>
                        <span wire:loading wire:target="accept">{{ __('Saving…') }}</span>
                    </x-ui.button>
                </div>
            </form>

            <div class="mt-4">
                <p class="form-text">{{ __('If you don’t agree, you can sign out. You can also close your account from Settings after signing in again.') }}</p>
                <livewire:member.auth.logout-button />
            </div>
        </div>
    </section>
</div>
