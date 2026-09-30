{{-- /register (template register.php, M01). Template markup kept; gender and country code added
     (PRD M01), the demo <a href="profile-creation"> becomes a real submit button. --}}
@php($genderLocked = $form->genderIsDerived())

<div>
    <h1 class="visually-hidden">{{ __('Register on Oppam Matrimony') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img2.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('Your story starts with a profile.') }}</h3>
                                <p>{{ __('Free to join. Verified Kerala brides and grooms, matched with care.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h2>{{ __('Register free') }}</h2>
                                <p>{{ __('A few details now — you can complete the rest of your profile next.') }}</p>
                            </div>

                            <form wire:submit="register" novalidate>
                                <div class="row reg-grid">
                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="profileFor" class="login-label">{{ __('Matrimony profile for') }}</label>
                                            <select id="profileFor" name="created_for" wire:model.live="form.createdFor" required
                                                    class="form-select login-input @error('form.createdFor') is-invalid @enderror"
                                                    @error('form.createdFor') aria-invalid="true" aria-describedby="profileFor-error" @enderror>
                                                <option value="">{{ __('Select an option') }}</option>
                                                @foreach ($createdForOptions as $value => $label)
                                                    <option value="{{ $value }}">{{ $label }}</option>
                                                @endforeach
                                            </select>
                                            @error('form.createdFor')<p class="invalid-feedback d-block" id="profileFor-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>

                                    <div class="col-md-6 col-12">
                                        <div class="login-field">
                                            <label for="regName" class="login-label">{{ __('Name') }}</label>
                                            <input type="text" id="regName" name="name" wire:model="form.name" maxlength="120" autocomplete="name" required
                                                   class="form-control login-input @error('form.name') is-invalid @enderror" placeholder="{{ __('Full name') }}"
                                                   @error('form.name') aria-invalid="true" aria-describedby="regName-error" @enderror>
                                            @error('form.name')<p class="invalid-feedback d-block" id="regName-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <fieldset class="login-field" @error('form.gender') aria-describedby="regGender-error" @enderror>
                                            <legend class="login-label">{{ __('Gender') }}</legend>
                                            <div class="d-flex gap-4">
                                                <div class="form-check login-check">
                                                    <input class="form-check-input" type="radio" name="gender" id="regGenderMale" value="MALE" wire:model="form.gender" required @disabled($genderLocked)>
                                                    <label class="form-check-label" for="regGenderMale">{{ __('Male') }}</label>
                                                </div>
                                                <div class="form-check login-check">
                                                    <input class="form-check-input" type="radio" name="gender" id="regGenderFemale" value="FEMALE" wire:model="form.gender" @disabled($genderLocked)>
                                                    <label class="form-check-label" for="regGenderFemale">{{ __('Female') }}</label>
                                                </div>
                                            </div>
                                            @if ($genderLocked)
                                                <p class="form-text">{{ __('Set from “Matrimony profile for”.') }}</p>
                                            @endif
                                            @error('form.gender')<p class="invalid-feedback d-block" id="regGender-error">{{ $message }}</p>@enderror
                                        </fieldset>
                                    </div>

                                    {{-- Full width: the country select (added for NRI members) needs the room. --}}
                                    <div class="col-12">
                                        <div class="login-field">
                                            <label for="regMobile" class="login-label">{{ __('Mobile number') }}</label>
                                            <div class="row g-2">
                                                <div class="col-5 col-sm-4">
                                                    <select id="regCountry" name="country_code" wire:model="form.countryCode" class="form-select login-input" aria-label="{{ __('Country code') }}">
                                                        @foreach ($countryOptions as $code => $label)
                                                            <option value="{{ $code }}">{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-7 col-sm-8">
                                                    <input type="tel" id="regMobile" name="mobile" wire:model="form.mobile" inputmode="tel" maxlength="20" autocomplete="tel-national" required
                                                           class="form-control login-input @error('form.mobile') is-invalid @enderror" placeholder="{{ __('10-digit mobile number') }}"
                                                           @error('form.mobile') aria-invalid="true" aria-describedby="regMobile-error" @enderror>
                                                </div>
                                            </div>
                                            @error('form.mobile')<p class="invalid-feedback d-block" id="regMobile-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="login-field">
                                            <label for="regEmail" class="login-label">{{ __('Email (optional)') }}</label>
                                            <input type="email" id="regEmail" name="email" wire:model="form.email" maxlength="255" autocomplete="email"
                                                   class="form-control login-input @error('form.email') is-invalid @enderror" placeholder="you@example.com"
                                                   @error('form.email') aria-invalid="true" aria-describedby="regEmail-error" @enderror>
                                            @error('form.email')<p class="invalid-feedback d-block" id="regEmail-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>

                                    <div class="col-12">
                                        <div class="login-field">
                                            <label for="regPassword" class="login-label">{{ __('Password') }}</label>
                                            <input type="password" id="regPassword" name="password" wire:model="form.password" maxlength="72" autocomplete="new-password" required
                                                   class="form-control login-input @error('form.password') is-invalid @enderror" placeholder="{{ __('At least 8 characters, letters and numbers') }}"
                                                   @error('form.password') aria-invalid="true" aria-describedby="regPassword-error" @enderror>
                                            @error('form.password')<p class="invalid-feedback d-block" id="regPassword-error">{{ $message }}</p>@enderror
                                        </div>
                                    </div>
                                </div>

                                <div class="login-meta reg-meta">
                                    <div class="form-check login-check">
                                        <input type="checkbox" class="form-check-input @error('form.terms') is-invalid @enderror" id="regTerms" name="terms" value="1" wire:model="form.terms"
                                               @error('form.terms') aria-invalid="true" aria-describedby="regTerms-error" @enderror>
                                        <label class="form-check-label" for="regTerms">
                                            {{ __('I agree to the') }} <a href="{{ route('terms') }}" class="login-forgot" wire:navigate>{{ __('terms of use') }}</a>
                                            {{ __('and') }} <a href="{{ route('privacy') }}" class="login-forgot" wire:navigate>{{ __('privacy policy') }}</a>
                                        </label>
                                        @error('form.terms')<p class="invalid-feedback d-block" id="regTerms-error">{{ $message }}</p>@enderror
                                    </div>
                                </div>

                                <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="register">
                                    <span class="ui-spinner" wire:loading wire:target="register" aria-hidden="true"></span>
                                    {{ __('Register') }}
                                </button>

                                <p class="login-prompt">{{ __('Already a member?') }} <a href="{{ route('login') }}" wire:navigate>{{ __('Login') }}</a></p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
