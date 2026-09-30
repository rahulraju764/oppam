{{-- Home hero registration (template index.php hero form, M01). Same markup and classes as the
     template; "Profile for" added (PRD M01), gender locked when "Profile for" implies it. --}}
@inject('nav', 'App\Support\Navigation\Navigation')
@php($loginUrl = $nav->url('login'))
@php($genderLocked = $form->genderIsDerived())

<form class="register-form" wire:submit="register" novalidate>
    <div class="register-head">
        <p class="eyebrow">{{ __('Official matrimony service') }}</p>
        <h2>{{ __('Create your free profile') }}</h2>
    </div>

    {{-- Visually-hidden labels: the placeholder says the same word, and the form must fit the banner. --}}
    <div class="form-row">
        <label class="form-label visually-hidden" for="reg-created-for">{{ __('Matrimony profile for') }}</label>
        <select class="form-select mat-register @error('form.createdFor') is-invalid @enderror" id="reg-created-for" name="created_for"
                wire:model.live="form.createdFor" required
                @error('form.createdFor') aria-invalid="true" aria-describedby="reg-created-for-error" @enderror>
            <option value="">{{ __('Profile for…') }}</option>
            @foreach ($createdForOptions as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        @error('form.createdFor')<p class="invalid-feedback d-block" id="reg-created-for-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-row">
        <label class="form-label visually-hidden" for="reg-first-name">{{ __('First name') }}</label>
        <input type="text" class="form-control mat-register @error('form.name') is-invalid @enderror" id="reg-first-name" name="first_name"
               wire:model="form.name" placeholder="{{ __('First name') }}" autocomplete="given-name" maxlength="120" required
               @error('form.name') aria-invalid="true" aria-describedby="reg-first-name-error" @enderror>
        @error('form.name')<p class="invalid-feedback d-block" id="reg-first-name-error">{{ $message }}</p>@enderror
    </div>

    <fieldset class="form-row gender-head" @error('form.gender') aria-describedby="reg-gender-error" @enderror>
        <legend class="form-label">{{ __('Gender') }}</legend>
        <div class="gender-options">
            <div class="form-check">
                <input class="form-check-input" type="radio" name="gender" id="reg-gender-male" value="MALE" wire:model="form.gender" required @disabled($genderLocked)>
                <label class="form-check-label" for="reg-gender-male">{{ __('Male') }}</label>
            </div>
            <div class="form-check">
                <input class="form-check-input" type="radio" name="gender" id="reg-gender-female" value="FEMALE" wire:model="form.gender" @disabled($genderLocked)>
                <label class="form-check-label" for="reg-gender-female">{{ __('Female') }}</label>
            </div>
        </div>
        @error('form.gender')<p class="invalid-feedback d-block" id="reg-gender-error">{{ $message }}</p>@enderror
    </fieldset>

    <fieldset class="form-row" @error('form.dob') aria-describedby="reg-dob-error" @enderror>
        <legend class="form-label">{{ __('Date of birth') }}</legend>
        <div class="row g-2">
            <div class="col-4">
                <input type="text" class="form-control mat-register @error('form.dob') is-invalid @enderror" id="reg-dob-day" name="dob_day" wire:model="form.dobDay" placeholder="DD" aria-label="{{ __('Date of birth: day') }}" inputmode="numeric" maxlength="2" autocomplete="bday-day" required>
            </div>
            <div class="col-4">
                <input type="text" class="form-control mat-register @error('form.dob') is-invalid @enderror" id="reg-dob-month" name="dob_month" wire:model="form.dobMonth" placeholder="MM" aria-label="{{ __('Date of birth: month') }}" inputmode="numeric" maxlength="2" autocomplete="bday-month" required>
            </div>
            <div class="col-4">
                <input type="text" class="form-control mat-register @error('form.dob') is-invalid @enderror" id="reg-dob-year" name="dob_year" wire:model="form.dobYear" placeholder="YYYY" aria-label="{{ __('Date of birth: year') }}" inputmode="numeric" maxlength="4" autocomplete="bday-year" required>
            </div>
        </div>
        @error('form.dob')<p class="invalid-feedback d-block" id="reg-dob-error">{{ $message }}</p>@enderror
    </fieldset>

    <div class="form-row">
        <label class="form-label visually-hidden" for="reg-email">{{ __('Email') }}</label>
        <input type="email" class="form-control mat-register @error('form.email') is-invalid @enderror" id="reg-email" name="email" wire:model="form.email"
               placeholder="you@example.com" autocomplete="email" maxlength="255"
               @error('form.email') aria-invalid="true" aria-describedby="reg-email-error" @enderror>
        @error('form.email')<p class="invalid-feedback d-block" id="reg-email-error">{{ $message }}</p>@enderror
    </div>

    <fieldset class="form-row" @error('form.mobile') aria-describedby="reg-mobile-error" @enderror>
        <legend class="form-label">{{ __('Mobile number') }}</legend>
        <div class="row g-2">
            <div class="col-5">
                <select class="form-select mat-register" id="reg-country-code" name="country_code" wire:model="form.countryCode" aria-label="{{ __('Country code') }}">
                    @foreach ($countryOptions as $code => $label)
                        <option value="{{ $code }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-7">
                <input type="tel" class="form-control mat-register @error('form.mobile') is-invalid @enderror" id="reg-mobile" name="mobile" wire:model="form.mobile"
                       placeholder="{{ __('Mobile number') }}" aria-label="{{ __('Mobile number') }}" autocomplete="tel-national" inputmode="tel" maxlength="20" required
                       @error('form.mobile') aria-invalid="true" @enderror>
            </div>
        </div>
        @error('form.mobile')<p class="invalid-feedback d-block" id="reg-mobile-error">{{ $message }}</p>@enderror
    </fieldset>

    <div class="form-row">
        <label class="form-label visually-hidden" for="reg-password">{{ __('Password') }}</label>
        <input type="password" class="form-control mat-register @error('form.password') is-invalid @enderror" id="reg-password" name="password" wire:model="form.password"
               placeholder="{{ __('Password (8+ letters & numbers)') }}" autocomplete="new-password" maxlength="72" required
               @error('form.password') aria-invalid="true" aria-describedby="reg-password-error" @enderror>
        @error('form.password')<p class="invalid-feedback d-block" id="reg-password-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-check tick-box">
        <input class="form-check-input @error('form.terms') is-invalid @enderror" type="checkbox" id="reg-terms" name="terms" value="1" wire:model="form.terms" required
               @error('form.terms') aria-invalid="true" aria-describedby="reg-terms-error" @enderror>
        <label class="form-check-label" for="reg-terms">
            {{ __('I have read and agreed to the') }}
            <a href="{{ route('terms') }}" wire:navigate>{{ __('Terms of Use') }}</a>
            {{ __('and') }} <a href="{{ route('privacy') }}" wire:navigate>{{ __('Privacy Policy') }}</a>
        </label>
        @error('form.terms')<p class="invalid-feedback d-block" id="reg-terms-error">{{ $message }}</p>@enderror
    </div>

    <div class="regi-button">
        <button type="submit" wire:loading.attr="disabled" wire:target="register">
            <span class="ui-spinner" wire:loading wire:target="register" aria-hidden="true"></span>
            {{ __('Create an account for free') }}
        </button>
    </div>

    @if ($loginUrl)
        <p class="account">{{ __('Already have an account?') }} <a href="{{ $loginUrl }}" wire:navigate>{{ __('Login') }}</a></p>
    @endif
</form>
