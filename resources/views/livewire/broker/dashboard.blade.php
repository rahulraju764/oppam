{{--
    /broker (B.4) — centre column + right rail of the 3/6/3 row opened by layouts.broker. The status
    card says what to do next (KYC); each widget shows zeros / an empty state until its module ships.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Welcome, :name', ['name' => $broker->name]) }}</h1>
                <p>{{ $broker->displayName() }} · {{ $broker->code }}</p>
            </div>

            @if ($broker->isVerifiedAndActive())
                <x-ui.alert type="success">{{ __('Your bureau is verified. You can add and import client profiles as they open in the portal.') }}</x-ui.alert>
            @elseif ($isOwner && $latestKyc?->status === \App\Enums\KycSubmissionStatus::Rejected)
                <x-ui.alert type="warning" :title="__('Please check your KYC')">
                    {{ $latestKyc->rejection_reason }}
                    <a href="{{ route('broker.kyc') }}" wire:navigate>{{ __('Send KYC again') }}</a>
                </x-ui.alert>
            @elseif ($broker->kyc_status === \App\Enums\BrokerKycStatus::Pending)
                <x-ui.alert type="info" :title="__('KYC under review')">{{ __('We are checking your PAN and bank details — usually within two working days. Creating, importing and exporting profiles opens once it is approved.') }}</x-ui.alert>
            @elseif ($isOwner)
                <x-ui.alert type="info" :title="__('Next: KYC')">
                    {{ __('Send your PAN and bank details. Until KYC is approved you can explore the portal, but not add client profiles.') }}
                    <a href="{{ route('broker.kyc') }}" wire:navigate>{{ __('Complete KYC') }}</a>
                </x-ui.alert>
            @else
                <x-ui.alert type="info">{{ __('Your bureau’s KYC is not complete yet. The owner needs to finish it before profiles can be added.') }}</x-ui.alert>
            @endif
        </div>

        <div class="row g-3">
            @foreach ($widgets as $widget)
                <div class="col-md-6 col-12" wire:key="widget-{{ $widget['key'] }}">
                    <div class="dashboard-content broker-widget h-100">
                        <h2 class="broker-widget__title"><i class="fa {{ $widget['icon'] }}" aria-hidden="true"></i> {{ $widget['title'] }}</h2>
                        @if (isset($widget['stats']))
                            <dl class="broker-widget__stats">
                                @foreach ($widget['stats'] as $label => $value)
                                    <div><dt>{{ $label }}</dt><dd>{{ $value }}</dd></div>
                                @endforeach
                            </dl>
                        @elseif (($widget['items'] ?? []) !== [])
                            {{-- P7.3: moderation feed / follow-ups — profile codes linking to the scoped detail page. --}}
                            <ul class="broker-feed">
                                @foreach ($widget['items'] as $item)
                                    <li>
                                        <a href="{{ $item['url'] }}" wire:navigate>{{ $item['label'] }}</a>
                                        <x-ui.badge :variant="$item['variant']">{{ $item['badge'] }}</x-ui.badge>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-muted-brand mb-0">{{ $widget['empty'] }}</p>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('How it works') }}</h2>
            <ol class="broker-help__steps">
                <li>{{ __('Complete KYC: PAN and bank account for payouts.') }}</li>
                <li>{{ __('Add your clients’ profiles with their consent — every profile is reviewed.') }}</li>
                <li>{{ __('Share your code with families; earn commission when they upgrade.') }}</li>
            </ol>
            <p class="text-muted-brand small mb-0">{{ __('Your clients’ data is private: only your bureau sees the profiles it runs.') }}</p>
        </div>
    </div>
</div>
