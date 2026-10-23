{{-- /broker/apply (§11A B.11) — the template's login card (as /register), with the bureau fields. --}}
<div>
    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-5 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h2>{{ __('Bring your bureau to Oppam') }}</h2>
                                <p>{{ __('Run your clients’ profiles, refer families and earn commission — after a quick KYC check.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-7 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h1>{{ __('Apply as a marriage bureau') }}</h1>
                                <p>{{ __('We check every bureau before it can add client profiles. You’ll confirm your mobile next, then send your PAN and bank details.') }}</p>
                            </div>

                            <form wire:submit="apply" novalidate>
                                <div class="row reg-grid">
                                    @foreach ([
                                        ['name', 'brName', __('Your name'), 'text', 'name', 100, true],
                                        ['bureauName', 'brBureau', __('Bureau name (optional)'), 'text', 'organization', 120, false],
                                        ['mobile', 'brMobile', __('Mobile number (India)'), 'tel', 'tel-national', 20, true],
                                        ['email', 'brEmail', __('Email (optional)'), 'email', 'email', 255, false],
                                        ['pan', 'brPan', __('PAN'), 'text', 'off', 10, true],
                                        ['password', 'brPassword', __('Password'), 'password', 'new-password', 72, true],
                                    ] as [$field, $id, $label, $type, $autocomplete, $max, $required])
                                        <div class="col-md-6 col-12">
                                            <div class="login-field">
                                                <label for="{{ $id }}" class="login-label">{{ $label }}</label>
                                                <input type="{{ $type }}" id="{{ $id }}" name="{{ $field }}" wire:model="form.{{ $field }}" maxlength="{{ $max }}"
                                                       autocomplete="{{ $autocomplete }}" @if ($required) required @endif @if ($field === 'pan') spellcheck="false" @endif
                                                       class="form-control login-input @error('form.'.$field) is-invalid @enderror"
                                                       @error('form.'.$field) aria-invalid="true" aria-describedby="{{ $id }}-error" @enderror>
                                                @error('form.'.$field)<p class="invalid-feedback d-block" id="{{ $id }}-error">{{ $message }}</p>@enderror
                                            </div>
                                        </div>
                                    @endforeach

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="brDistrict" class="login-label">{{ __('District') }}</label>
                                            <select id="brDistrict" name="district_id" wire:model="form.districtId" required
                                                    class="form-select login-input @error('form.districtId') is-invalid @enderror"
                                                    @error('form.districtId') aria-invalid="true" aria-describedby="brDistrict-error" @enderror>
                                                <option value="">{{ __('Select a district') }}</option>
                                                @foreach ($districts as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('form.districtId')<p class="invalid-feedback d-block" id="brDistrict-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="login-meta reg-meta">
                                    <div class="form-check login-check">
                                        <input type="checkbox" class="form-check-input @error('form.terms') is-invalid @enderror" id="brTerms" name="terms" value="1" wire:model="form.terms"
                                               @error('form.terms') aria-invalid="true" aria-describedby="brTerms-error" @enderror>
                                        <label class="form-check-label" for="brTerms">
                                            {{ __('I agree to the') }} <a href="{{ route('terms') }}" class="login-forgot" wire:navigate>{{ __('terms of use') }}</a>
                                            {{ __('and') }} <a href="{{ route('privacy') }}" class="login-forgot" wire:navigate>{{ __('privacy policy') }}</a>
                                        </label>
                                        @error('form.terms')<p class="invalid-feedback d-block" id="brTerms-error">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <button type="submit" class="btn login-btn" dusk="broker-apply" wire:loading.attr="disabled" wire:target="apply">
                                    <span class="ui-spinner" wire:loading wire:target="apply" aria-hidden="true"></span>
                                    {{ __('Apply') }}
                                </button>

                                <p class="login-prompt">{{ __('Already have a bureau login?') }} <a href="{{ route('login') }}" wire:navigate>{{ __('Login') }}</a></p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
