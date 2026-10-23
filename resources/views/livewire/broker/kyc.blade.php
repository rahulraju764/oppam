{{--
    /broker/kyc (B.11) — status + form (template .vf-* form styles from verification.php, the same
    kind of document upload). Masked last-4 only; never the full PAN or account number.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4" aria-live="polite">
            <div class="content-header">
                <h1>{{ __('KYC & bank details') }}</h1>
                <p>{{ __('PAN and a bank account for commission payouts. Required before your bureau can add, import or export client profiles.') }}</p>
            </div>

            @if ($status)
                <x-ui.alert type="success">{{ $status }}</x-ui.alert>
            @endif

            <dl class="info-grid broker-kyc__status">
                <div class="info-pair"><dt>{{ __('KYC status') }}</dt><dd><x-ui.badge :variant="$broker->kyc_status === \App\Enums\BrokerKycStatus::Verified ? 'verified' : 'warning'">{{ $broker->kyc_status->label() }}</x-ui.badge></dd></div>
                @if ($latest)
                    <div class="info-pair"><dt>{{ __('PAN') }}</dt><dd>{{ $latest->maskedPan() }}</dd></div>
                    <div class="info-pair"><dt>{{ __('Bank account') }}</dt><dd>{{ $latest->maskedAccount() }} · {{ $latest->ifsc }}</dd></div>
                @endif
            </dl>

            @if ($latest?->status === \App\Enums\KycSubmissionStatus::Rejected && $showForm)
                <x-ui.alert type="warning" :title="__('Please correct and send again')">{{ $latest->rejection_reason }}</x-ui.alert>
            @endif
        </div>

        @if ($showForm)
            <div class="dashboard-content">
                <div class="content-header">
                    <h2>{{ __('Send KYC') }}</h2>
                    <p>{{ __('PAN card as JPG, PNG or PDF, up to :mb MB. Stored encrypted; only our verification team sees it.', ['mb' => $maxMb]) }}</p>
                </div>

                <form class="vf-form" wire:submit="submit" novalidate>
                    <div class="vf-field">
                        <label for="kycPan" class="login-label">{{ __('PAN') }}</label>
                        <input type="text" id="kycPan" name="pan" wire:model="pan" maxlength="10" autocomplete="off" spellcheck="false" required
                               class="form-control login-input @error('pan') is-invalid @enderror" placeholder="ABCDE1234F"
                               @error('pan') aria-invalid="true" aria-describedby="kycPan-error" @enderror>
                        @error('pan')<p class="invalid-feedback d-block" id="kycPan-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="vf-field">
                        <label for="kycPanImage" class="login-label">{{ __('PAN card — photo or scan') }}</label>
                        <input type="file" id="kycPanImage" name="pan_image" wire:model="panImage" accept="image/jpeg,image/png,application/pdf" required
                               class="form-control login-input @error('panImage') is-invalid @enderror"
                               @error('panImage') aria-invalid="true" aria-describedby="kycPanImage-error" @enderror>
                        <p class="vf-hint" wire:loading wire:target="panImage" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ __('Uploading…') }}</p>
                        @error('panImage')<p class="invalid-feedback d-block" id="kycPanImage-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="vf-field">
                        <label for="kycAccountName" class="login-label">{{ __('Account holder name') }}</label>
                        <input type="text" id="kycAccountName" name="account_name" wire:model="accountName" maxlength="100" autocomplete="name" required
                               class="form-control login-input @error('accountName') is-invalid @enderror"
                               @error('accountName') aria-invalid="true" aria-describedby="kycAccountName-error" @enderror>
                        @error('accountName')<p class="invalid-feedback d-block" id="kycAccountName-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="vf-field">
                        <label for="kycAccount" class="login-label">{{ __('Account number') }}</label>
                        <input type="text" id="kycAccount" name="account_number" wire:model="accountNumber" maxlength="22" inputmode="numeric" autocomplete="off" required
                               class="form-control login-input @error('accountNumber') is-invalid @enderror"
                               @error('accountNumber') aria-invalid="true" aria-describedby="kycAccount-error" @enderror>
                        @error('accountNumber')<p class="invalid-feedback d-block" id="kycAccount-error">{{ $message }}</p>@enderror
                    </div>

                    <div class="vf-field">
                        <label for="kycIfsc" class="login-label">{{ __('IFSC') }}</label>
                        <input type="text" id="kycIfsc" name="ifsc" wire:model="ifsc" maxlength="11" autocomplete="off" spellcheck="false" required
                               class="form-control login-input @error('ifsc') is-invalid @enderror" placeholder="SBIN0001234"
                               @error('ifsc') aria-invalid="true" aria-describedby="kycIfsc-error" @enderror>
                        @error('ifsc')<p class="invalid-feedback d-block" id="kycIfsc-error">{{ $message }}</p>@enderror
                    </div>

                    <button type="submit" class="btn login-btn" dusk="kyc-submit" wire:loading.attr="disabled" wire:target="submit,panImage">
                        <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                        {{ __('Send for verification') }}
                    </button>
                </form>
            </div>
        @elseif ($broker->kyc_status === \App\Enums\BrokerKycStatus::Pending)
            <div class="dashboard-content">
                <p class="mb-0">{{ __('Your KYC is being reviewed — usually within two working days. This page shows the result.') }}</p>
            </div>
        @endif
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar vf-why">
            <h2>{{ __('Why KYC?') }}</h2>
            <ul class="vf-why-list">
                <li><i class="fa fa-check" aria-hidden="true"></i> {{ __('Your bureau handles other people’s personal data — we confirm who runs it.') }}</li>
                <li><i class="fa fa-check" aria-hidden="true"></i> {{ __('Commission is paid only to a verified bank account.') }}</li>
            </ul>
        </div>
    </div>
</div>
