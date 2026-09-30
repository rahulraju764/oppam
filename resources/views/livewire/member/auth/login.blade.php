{{-- /login (template login.php, PRD §8.2). The template's two demo anchors become real buttons:
     "Login" submits the password form, "Login with OTP" switches to the mobile + code form. --}}
<div>
    <h1 class="visually-hidden">{{ __('Log in to Oppam Matrimony') }}</h1>

    <section class="login-section">
        <div class="container is-readable">
            <div class="login-card">
                <div class="row g-0 align-items-stretch">
                    <div class="col-lg-6 d-none d-lg-block">
                        <div class="login-visual">
                            <img src="{{ asset('images/login/login-img1.jpg') }}" alt="{{ __('Newly married Kerala couple') }}" width="470" height="650" fetchpriority="high" decoding="async">
                            <div class="login-visual-caption">
                                <h3>{{ __('Find someone who feels like home.') }}</h3>
                                <p>{{ __('Thousands of verified Kerala profiles, and the next one could be yours.') }}</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-6 col-12">
                        <div class="login-form">
                            <div class="login-head">
                                <h2>{{ __('Welcome back') }}</h2>
                                <p>{{ __('Log in to continue your search for the right match.') }}</p>
                            </div>

                            @if (session('status'))
                                <x-ui.alert type="info">{{ session('status') }}</x-ui.alert>
                            @endif

                            @if ($mode === \App\Livewire\Member\Auth\Login::MODE_PASSWORD)
                                <form wire:submit="loginWithPassword" novalidate wire:key="login-password">
                                    <div class="login-field">
                                        <label for="loginId" class="login-label">{{ __('Mobile No. / Email Id / Profile ID') }}</label>
                                        <input type="text" class="form-control login-input @error('loginId') is-invalid @enderror" id="loginId" name="login_id"
                                               wire:model="loginId" maxlength="255" autocomplete="username" required
                                               placeholder="{{ __('Enter your mobile number, email or OPM ID') }}"
                                               @error('loginId') aria-invalid="true" aria-describedby="loginId-error" @enderror>
                                        @error('loginId')<p class="invalid-feedback d-block" id="loginId-error" role="alert">{{ $message }}</p>@enderror
                                    </div>

                                    <div class="login-field">
                                        <label for="loginPassword" class="login-label">{{ __('Password') }}</label>
                                        <input type="password" class="form-control login-input @error('password') is-invalid @enderror" id="loginPassword" name="password"
                                               wire:model="password" maxlength="255" autocomplete="current-password" required
                                               placeholder="{{ __('Enter your password') }}"
                                               @error('password') aria-invalid="true" aria-describedby="loginPassword-error" @enderror>
                                        @error('password')<p class="invalid-feedback d-block" id="loginPassword-error">{{ $message }}</p>@enderror
                                    </div>

                                    <div class="login-meta">
                                        <div class="form-check login-check">
                                            <input type="checkbox" class="form-check-input" id="stayLoggedIn" name="stay_logged_in" value="1" wire:model="remember">
                                            <label class="form-check-label" for="stayLoggedIn">{{ __('Stay logged in') }}</label>
                                        </div>
                                        <a href="{{ route('password.forgot') }}" class="login-forgot" wire:navigate>{{ __('Forgot password?') }}</a>
                                    </div>

                                    <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="loginWithPassword">
                                        <span class="ui-spinner" wire:loading wire:target="loginWithPassword" aria-hidden="true"></span>
                                        {{ __('Login') }}
                                    </button>
                                    <button type="button" class="btn login-btn-alt" wire:click="useOtp">{{ __('Login with OTP') }}</button>
                                </form>
                            @else
                                <form wire:submit="{{ $codeSent ? 'loginWithOtp' : 'sendCode' }}" novalidate wire:key="login-otp">
                                    <div class="login-field">
                                        <label for="loginMobile" class="login-label">{{ __('Mobile number') }}</label>
                                        <div class="row g-2">
                                            <div class="col-5">
                                                <select id="loginCountry" name="country_code" wire:model="countryCode" class="form-select login-input" aria-label="{{ __('Country code') }}" @disabled($codeSent)>
                                                    @foreach ($countryOptions as $code => $label)
                                                        <option value="{{ $code }}">{{ $label }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div class="col-7">
                                                <input type="tel" id="loginMobile" name="mobile" wire:model="mobile" inputmode="tel" maxlength="20" autocomplete="tel-national" required
                                                       class="form-control login-input @error('mobile') is-invalid @enderror" placeholder="{{ __('Registered mobile number') }}" @readonly($codeSent)
                                                       @error('mobile') aria-invalid="true" aria-describedby="loginMobile-error" @enderror>
                                            </div>
                                        </div>
                                        @error('mobile')<p class="invalid-feedback d-block" id="loginMobile-error" role="alert">{{ $message }}</p>@enderror
                                    </div>

                                    @if ($codeSent)
                                        <x-ui.alert type="info">{{ __('If this number is registered, we have sent a 6-digit code to it.') }}</x-ui.alert>

                                        @include('livewire.member.auth.partials.otp-code-field', ['resendAction' => 'sendCode'])

                                        <div class="login-meta">
                                            <div class="form-check login-check">
                                                <input type="checkbox" class="form-check-input" id="stayLoggedInOtp" name="stay_logged_in" value="1" wire:model="remember">
                                                <label class="form-check-label" for="stayLoggedInOtp">{{ __('Stay logged in') }}</label>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="loginWithOtp">
                                            <span class="ui-spinner" wire:loading wire:target="loginWithOtp" aria-hidden="true"></span>
                                            {{ __('Login') }}
                                        </button>
                                    @else
                                        <button type="submit" class="btn login-btn" wire:loading.attr="disabled" wire:target="sendCode">
                                            <span class="ui-spinner" wire:loading wire:target="sendCode" aria-hidden="true"></span>
                                            {{ __('Send code') }}
                                        </button>
                                    @endif

                                    <button type="button" class="btn login-btn-alt" wire:click="usePassword">{{ __('Login with password') }}</button>
                                </form>
                            @endif

                            <p class="login-prompt">{{ __('New to Oppam?') }} <a href="{{ route('register') }}" wire:navigate>{{ __('Create an account') }}</a></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
