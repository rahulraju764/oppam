{{-- /onboarding/submitted — profile under review (R-M02-2). Same section frame as the wizard. --}}
<div>
    <section class="register-section">
        <div class="container is-readable">
            <div class="register-heading">
                <h1 class="profile-title">{{ __('Profile submitted') }}</h1>
                <p class="profile-subtitle">{{ __('Thank you! Our team reviews every profile to keep Oppam safe.') }}</p>
            </div>

            <x-ui.card>
                <x-ui.empty-state icon="fa-hourglass-half" :title="__('Your profile :code is under review', ['code' => $profile?->code])"
                    :message="__('Reviews usually finish within 24 hours. We will let you know as soon as your profile is live — then you can start searching and connecting.')">
                    <p class="form-text mb-0">
                        {{ __('Profile completeness: :percent%', ['percent' => $profile?->completeness ?? 0]) }}
                    </p>
                </x-ui.empty-state>
            </x-ui.card>
        </div>
    </section>
</div>
