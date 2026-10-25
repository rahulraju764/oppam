{{--
    /broker/materials (B.5) — centre column + right rail of the 3/6/3 row opened by layouts.broker:
    the bureau code, a copyable link, the QR code and the downloads (SVG, PNG, A4 poster PDF).
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Referral materials') }}</h1>
                <p>{{ __('Share your code with families. When they register with it and buy a plan, you earn commission.') }}</p>
            </div>

            @unless ($active)
                <x-ui.alert type="info">{{ __('You can share these now. Referrals start counting once your bureau’s KYC is approved — families who join before that are linked after our team checks.') }}</x-ui.alert>
            @endunless

            <div class="broker-materials__code">
                <span class="broker-materials__label">{{ __('Your code') }}</span>
                <strong class="broker-materials__value">{{ $broker->code }}</strong>
            </div>

            <div class="broker-materials__link" x-data="{ copied: false, copy() { navigator.clipboard?.writeText(this.$refs.link.value).then(() => { this.copied = true; setTimeout(() => this.copied = false, 2000); }); } }">
                <label for="referralLink" class="form-label">{{ __('Your referral link') }}</label>
                <div class="input-group">
                    <input type="text" id="referralLink" name="referral_link" class="form-control" value="{{ $link }}" readonly x-ref="link" x-on:focus="$event.target.select()">
                    <x-ui.button type="button" variant="outline" icon="fa-clipboard" x-on:click="copy()" dusk="copy-link">{{ __('Copy') }}</x-ui.button>
                </div>
                <p class="form-text" role="status" aria-live="polite"><span x-show="copied" x-cloak>{{ __('Link copied.') }}</span></p>
            </div>
        </div>

        <div class="dashboard-content broker-materials__qr">
            <h2 class="broker-widget__title"><i class="fa fa-qrcode" aria-hidden="true"></i> {{ __('QR code') }}</h2>
            <img src="{{ $qrDataUri }}" width="240" height="240" alt="{{ __('QR code for :link', ['link' => $link]) }}">
            <div class="broker-materials__downloads">
                <x-ui.button :href="route('broker.materials.qr.svg')" variant="outline" icon="fa-download" download>{{ __('QR (SVG)') }}</x-ui.button>
                <x-ui.button :href="route('broker.materials.qr.png')" variant="outline" icon="fa-download" download>{{ __('QR (PNG)') }}</x-ui.button>
                <x-ui.button :href="route('broker.materials.poster')" icon="fa-file-pdf-o" download dusk="poster-download">{{ __('A4 poster (PDF)') }}</x-ui.button>
            </div>
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('How referrals work') }}</h2>
            <ol class="broker-help__steps">
                <li>{{ __('A family opens your link or scans the QR — your code is remembered for 30 days.') }}</li>
                <li>{{ __('They register (or type your code) and their profile is approved.') }}</li>
                <li>{{ __('On their first paid plan you earn commission; a refund reverses it.') }}</li>
            </ol>
            <p class="text-muted-brand small mb-0">{{ __('You see referred members by profile code only — never their names or contact details.') }}</p>
        </div>
    </div>
</div>
