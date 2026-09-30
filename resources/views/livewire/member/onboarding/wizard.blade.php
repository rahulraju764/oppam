{{-- /onboarding/{step} — landing point after registration (M02). The six wizard steps replace
     this body in P1.2 / P1.3. --}}
<div>
    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="login-form">
                    <div class="login-head">
                        <p class="eyebrow">{{ __('Step :step of :total', ['step' => $step, 'total' => \App\Livewire\Member\Onboarding\Wizard::STEPS]) }}</p>
                        <h1>{{ __('Create your profile') }}</h1>
                        @if ($profile)
                            <p>{{ __('Welcome, :name. Your mobile number is verified and your profile :code is saved as a draft.', ['name' => $profile->first_name, 'code' => $profile->code]) }}</p>
                        @endif
                    </div>

                    <x-ui.alert type="info">{{ __('The profile form is being prepared. You will be able to add your details here very soon.') }}</x-ui.alert>
                </div>
            </div>
        </div>
    </section>
</div>
