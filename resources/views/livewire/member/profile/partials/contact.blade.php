{{-- Contact details block (R-M03-2): masked until ViewContact reveals them; locked states explain why. --}}
@unless ($isOwner)
    <div class="dtl-basic-info" id="contact-details">
        <div class="basic-info-hd"><h4>{{ __('Contact Details') }}</h4></div>
        <div class="dtl-sec">
            @if ($contact)
                <table class="table tb-basic-info">
                    <tr><td class="dtl-profile">{{ __('Mobile') }}</td><td class="dtls"><a href="tel:{{ $contact['phone'] }}">{{ $contact['phone'] }}</a></td></tr>
                    @if ($contact['alternatePhone'])<tr><td class="dtl-profile">{{ __('Alternate Mobile') }}</td><td class="dtls">{{ $contact['alternatePhone'] }}</td></tr>@endif
                    @if ($contact['email'])<tr><td class="dtl-profile">{{ __('Email') }}</td><td class="dtls">{{ $contact['email'] }}</td></tr>@endif
                    @if ($contact['contactPerson'])<tr><td class="dtl-profile">{{ __('Contact Person') }}</td><td class="dtls">{{ $contact['contactPerson'] }}@if ($contact['relation']) ({{ $contact['relation'] }})@endif</td></tr>@endif
                    @if ($contact['convenientTime'])<tr><td class="dtl-profile">{{ __('Best Time to Call') }}</td><td class="dtls">{{ $contact['convenientTime'] }}</td></tr>@endif
                </table>
            @else
                <table class="table tb-basic-info">
                    <tr><td class="dtl-profile">{{ __('Contact Number') }}</td><td class="dtls my-number">+91 ••••• •••••</td></tr>
                </table>

                @if ($contactError)
                    <x-ui.alert type="warning">{{ $contactError }}</x-ui.alert>
                @elseif ($asOthers && ! $preview && $contactAccess)
                    @if ($contactAccess->opens())
                        <p class="form-text">{{ $contactAccess->message() }}</p>
                        <button type="button" class="view-btn" x-data x-on:click="$dispatch('open-modal', { name: 'view-contact' })">
                            <i class="fa fa-phone" aria-hidden="true"></i> {{ __('View Contact') }}
                        </button>
                    @else
                        <p class="form-text"><i class="fa fa-lock" aria-hidden="true"></i> {{ $contactAccess->message() }}</p>
                        @if ($contactAccess->suggestsUpgrade() && $plansUrl)
                            <a href="{{ $plansUrl }}" class="view-btn" wire:navigate>{{ __('See plans') }}</a>
                        @endif
                    @endif
                @endif
            @endif
        </div>
    </div>

    @if (! $contact && $contactAccess?->opens())
        <x-ui.modal name="view-contact" :title="__('View contact details?')">
            <p>{{ $contactAccess === \App\Enums\ContactAccess::Revealed ? __('You have viewed these details before — this is free.') : __('This uses one of your monthly contact views. Viewing the same profile again later is free.') }}</p>
            <x-slot:footer>
                <button type="button" class="ui-link-btn" x-on:click="$dispatch('close-modal', { name: 'view-contact' })">{{ __('Cancel') }}</button>
                <button type="button" class="view-btn" wire:click="viewContact" wire:loading.attr="disabled" wire:target="viewContact">
                    <span class="ui-spinner" wire:loading wire:target="viewContact" aria-hidden="true"></span>
                    {{ __('View now') }}
                </button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
@endunless
