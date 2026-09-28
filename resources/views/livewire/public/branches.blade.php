{{-- Our Branches (template branches.php). Demo offices until the branches table (A08, P8.1). --}}
<div>
    <section class="branches-section">
        <div class="container is-chrome">
            <div class="section-header">
                <p class="eyebrow">{{ __('Our Offices') }}</p>
                <h1>{{ __('Come and talk to us in person') }}</h1>
                @include('livewire.public.partials.title-divider')
                <p class="branches-intro">{{ __('Bring your horoscope and your parents. No appointment needed, though calling ahead saves you a wait on Saturdays.') }}</p>
            </div>

            <div class="row g-4">
                @foreach ($branches as $branch)
                    <div class="col-lg-4 col-md-6 col-12">
                        <div class="branch-card">
                            <div class="branch-card-head">
                                <h2>{{ $branch['city'] }}</h2>
                                <span class="chip">{{ __($branch['role']) }}</span>
                            </div>
                            <address class="branch-card-body">
                                <p class="branch-line">
                                    <i class="fa fa-map-marker" aria-hidden="true"></i>
                                    <span>{{ $branch['address'] }}</span>
                                </p>
                                <p class="branch-line">
                                    <i class="fa fa-phone" aria-hidden="true"></i>
                                    <a href="tel:{{ $branch['tel'] }}">{{ $branch['phone'] }}</a>
                                </p>
                                <p class="branch-line">
                                    <i class="fa fa-clock-o" aria-hidden="true"></i>
                                    <span>{{ $branch['hours'] }}</span>
                                </p>
                            </address>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="row">
                <div class="col-12 text-center">
                    <p class="branches-more text-muted-brand">
                        {{ __('Looking for an office not listed here?') }}
                        <a href="{{ route('contact') }}" wire:navigate>{{ __('Get in touch') }}</a>
                        {{ __('and we will point you at your nearest one.') }}
                    </p>
                </div>
            </div>
        </div>
    </section>
</div>
