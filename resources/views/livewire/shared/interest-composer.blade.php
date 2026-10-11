{{--
    The Send Interest dialog (PRD M06): one of the ready messages (A11 list), or — on a paid plan —
    the member's own note; how many interests are left this month; Send. Opened for a profile by
    the `compose-interest` event from any <x-engagement.interest-button>. Renders an empty shell
    until then (no query on page load).
--}}
<div>
    <x-ui.modal name="send-interest" :title="$name !== '' ? __('Send interest to :name', ['name' => $name]) : __('Send interest')">
        <form wire:submit="send" id="send-interest-form">
            @if ($refusal)
                <x-ui.alert type="danger">{{ $refusal }}</x-ui.alert>
            @endif

            @if ($templates !== [])
                <fieldset class="interest-templates">
                    <legend class="mat-label">{{ __('Add a message') }}</legend>
                    @foreach ($templates as $option)
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="template" id="interest-template-{{ $option['code'] }}"
                                   value="{{ $option['code'] }}" wire:model="template">
                            <label class="form-check-label" for="interest-template-{{ $option['code'] }}">{{ $option['label'] }}</label>
                        </div>
                    @endforeach
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="template" id="interest-template-none" value="" wire:model="template">
                        <label class="form-check-label" for="interest-template-none">{{ __('No message') }}</label>
                    </div>
                    @error('template')<p class="invalid-feedback d-block" role="alert">{{ $message }}</p>@enderror
                </fieldset>
            @endif

            @if ($customAllowed)
                <x-ui.textarea :label="__('Or write your own')" name="message" id="interest-message" rows="3" maxlength="{{ $max }}"
                               wire:model="message" :hint="__('Up to :max characters. No phone numbers, email or social handles.', ['max' => $max])" />
            @elseif ($code !== '')
                <p class="form-text">{{ __('Writing your own message is part of the paid plans.') }}
                    <a href="{{ route('plans') }}" wire:navigate>{{ __('See plans') }}</a></p>
            @endif

            <p class="interest-quota mb-0" aria-live="polite">
                @if ($remaining === null)
                    {{ __('Your plan has unlimited interests.') }}
                @else
                    {{ trans_choice(':count interest left this month|:count interests left this month', $remaining, ['count' => $remaining]) }}
                @endif
            </p>
        </form>

        <x-slot:footer>
            <x-ui.button type="button" variant="ghost" x-on:click="$dispatch('close-modal', { name: 'send-interest' })">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button form="send-interest-form" icon="fa-envelope" loading="send">{{ __('Send interest') }}</x-ui.button>
        </x-slot:footer>
    </x-ui.modal>
</div>
