{{--
    /verify (M09) — template verification.php, the standard 3 / 6 / 3: "why verify" rail, the
    steps + the upload in the centre, the shared ads rail. States: verified, waiting (no form),
    form (first time, or again after a rejection / a revoked badge, with the officer's guidance).
--}}
<div>
    <section class="dashboard-section verification-section">
        <div class="container">
            <div class="row dashboard-row">

                {{-- LEFT RAIL — why this is worth doing --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <div class="side-bar vf-why">
                        <h2>{{ __('Why verify?') }}</h2>
                        <ul class="vf-why-list">
                            <li><i class="fa fa-check" aria-hidden="true"></i> {{ __('A verified badge sits on your profile and in every search result.') }}</li>
                            <li><i class="fa fa-check" aria-hidden="true"></i> {{ __('Families filter for verified profiles — yours gets seen more.') }}</li>
                            <li><i class="fa fa-check" aria-hidden="true"></i> {{ __('Your document is seen by our verification team and nobody else. It is never shown on your profile, and it is deleted 90 days after the check.') }}</li>
                        </ul>
                        <p class="vf-why-note text-muted-brand">
                            {{ __('Read how we handle your data in our') }}
                            <a href="{{ route('privacy') }}" wire:navigate>{{ __('Privacy Policy') }}</a>.
                        </p>
                    </div>
                </div>

                {{-- CENTRE — steps + upload --}}
                <div class="col-lg-6 col-md-12 col-sm-12 col-12">
                    <div class="dashboard-content mb-4">
                        <div class="content-header">
                            <h1>{{ __('Verify your profile') }}</h1>
                            <p>{{ __(':done of :total steps done.', ['done' => $done, 'total' => count($steps)]) }}</p>
                        </div>

                        {{-- An ordered list: the steps happen in order. State is repeated in text (not colour only). --}}
                        <ol class="vf-steps">
                            @foreach ($steps as $step)
                                <li class="vf-step is-{{ $step['state'] }}" wire:key="step-{{ $loop->index }}">
                                    <span class="vf-step-mark" aria-hidden="true">
                                        <i @class(['fa', 'fa-check' => $step['state'] === 'done', 'fa-circle-o' => $step['state'] !== 'done'])></i>
                                    </span>
                                    <span class="vf-step-body">
                                        <span class="vf-step-title">
                                            {{ $step['title'] }}
                                            <span class="chip vf-step-state">{{ match ($step['state']) { 'done' => __('Done'), 'active' => __('Next'), default => __('Waiting') } }}</span>
                                        </span>
                                        <span class="vf-step-text">{{ $step['text'] }}</span>
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    </div>

                    <div class="dashboard-content" aria-live="polite">
                        @if ($status)
                            <x-ui.alert type="success">{{ $status }}</x-ui.alert>
                        @endif

                        @if ($state === 'verified')
                            <div class="content-header">
                                <h2>{{ __('You’re verified') }}</h2>
                                <p>{{ __('Your Verified badge shows on your profile and in search results.') }}</p>
                            </div>
                            <p><x-ui.badge variant="verified" icon="fa-check-circle">{{ __('Verified') }}</x-ui.badge></p>
                            <x-ui.button :href="route('member.profile.me')" variant="outline" icon="fa-user-o" wire:navigate>{{ __('View my profile') }}</x-ui.button>
                        @elseif ($state === 'pending')
                            <div class="content-header">
                                <h2>{{ __('Your document is being checked') }}</h2>
                                <p>{{ __('Our verification team checks documents in the order they arrive, usually within two working days. This page updates by itself.') }}</p>
                            </div>
                        @else
                            <div class="content-header">
                                <h2>{{ __('Upload your ID') }}</h2>
                                <p>{{ __('Aadhaar, passport, driving licence or voter ID. JPG, PNG or PDF, up to :mb MB.', ['mb' => $maxMb]) }}</p>
                            </div>

                            @if ($guidance)
                                <x-ui.alert type="warning" :title="__('Please try again')">{{ $guidance }}</x-ui.alert>
                            @endif

                            @if ($supportSession)
                                <x-ui.alert type="info">{{ __('Documents can’t be sent while our team is viewing this account for support.') }}</x-ui.alert>
                            @endif

                            <form class="vf-form" wire:submit="submit" novalidate>
                                <div class="vf-field">
                                    <label for="vfDocType" class="login-label">{{ __('Document type') }}</label>
                                    <select class="form-select login-input @error('docType') is-invalid @enderror" id="vfDocType" name="doc_type"
                                            wire:model.live="docType" required @error('docType') aria-invalid="true" aria-describedby="vfDocType-error" @enderror>
                                        <option value="">{{ __('Choose a document') }}</option>
                                        @foreach ($docTypes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('docType')<p class="invalid-feedback d-block" id="vfDocType-error">{{ $message }}</p>@enderror
                                </div>

                                <div class="vf-field">
                                    <label for="vfDocNumber" class="login-label">{{ __('Document number') }}</label>
                                    <input type="text" class="form-control login-input @error('number') is-invalid @enderror" id="vfDocNumber"
                                           name="doc_number" wire:model="number" maxlength="30" autocomplete="off" spellcheck="false" required
                                           placeholder="{{ __('As printed on the document') }}"
                                           aria-describedby="vfDocNumber-hint @error('number') vfDocNumber-error @enderror" @error('number') aria-invalid="true" @enderror>
                                    <p class="vf-hint text-muted-brand" id="vfDocNumber-hint">{{ $numberHint }}</p>
                                    @error('number')<p class="invalid-feedback d-block" id="vfDocNumber-error">{{ $message }}</p>@enderror
                                </div>

                                <div class="vf-field">
                                    <label for="vfDocName" class="login-label">{{ __('Name as printed on the document') }}</label>
                                    <input type="text" class="form-control login-input @error('nameOnDocument') is-invalid @enderror" id="vfDocName"
                                           name="name_on_document" wire:model="nameOnDocument" maxlength="100" autocomplete="name" required
                                           aria-describedby="vfDocName-hint @error('nameOnDocument') vfDocName-error @enderror" @error('nameOnDocument') aria-invalid="true" @enderror>
                                    <p class="vf-hint text-muted-brand" id="vfDocName-hint">{{ __('Initials and house names are fine — type it the way it appears.') }}</p>
                                    @error('nameOnDocument')<p class="invalid-feedback d-block" id="vfDocName-error">{{ $message }}</p>@enderror
                                </div>

                                <div class="vf-field">
                                    <label for="vfDocFront" class="login-label">{{ __('Front — photo or scan') }}</label>
                                    <input type="file" class="form-control login-input @error('front') is-invalid @enderror" id="vfDocFront" name="doc_front"
                                           wire:model="front" accept="image/jpeg,image/png,application/pdf" required
                                           aria-describedby="vfDocFront-hint @error('front') vfDocFront-error @enderror" @error('front') aria-invalid="true" @enderror>
                                    <p class="vf-hint text-muted-brand" id="vfDocFront-hint">{{ __('Make sure your name and date of birth are readable. You can cover everything else.') }}</p>
                                    <p class="vf-hint" wire:loading wire:target="front" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ __('Uploading…') }}</p>
                                    @error('front')<p class="invalid-feedback d-block" id="vfDocFront-error">{{ $message }}</p>@enderror
                                </div>

                                <div class="vf-field">
                                    <label for="vfDocBack" class="login-label">{{ __('Back (optional)') }}</label>
                                    <input type="file" class="form-control login-input @error('back') is-invalid @enderror" id="vfDocBack" name="doc_back"
                                           wire:model="back" accept="image/jpeg,image/png,application/pdf"
                                           aria-describedby="vfDocBack-hint @error('back') vfDocBack-error @enderror" @error('back') aria-invalid="true" @enderror>
                                    <p class="vf-hint text-muted-brand" id="vfDocBack-hint">{{ __('Add it if your date of birth is on the back.') }}</p>
                                    <p class="vf-hint" wire:loading wire:target="back" role="status"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span> {{ __('Uploading…') }}</p>
                                    @error('back')<p class="invalid-feedback d-block" id="vfDocBack-error">{{ $message }}</p>@enderror
                                </div>

                                <div class="form-check vf-check">
                                    <input type="checkbox" class="form-check-input @error('consent') is-invalid @enderror" id="vfConsent" name="consent" value="1"
                                           wire:model="consent" required @error('consent') aria-invalid="true" aria-describedby="vfConsent-error" @enderror>
                                    <label class="form-check-label" for="vfConsent">
                                        {{ __('I confirm this document is mine and agree to it being used to verify my profile.') }}
                                    </label>
                                    @error('consent')<p class="invalid-feedback d-block" id="vfConsent-error">{{ $message }}</p>@enderror
                                </div>

                                <button type="submit" class="btn login-btn" dusk="verify-submit" wire:loading.attr="disabled" wire:target="submit,front,back" @disabled($supportSession)>
                                    <span wire:loading wire:target="submit" class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                                    {{ __('Submit for verification') }}
                                </button>
                            </form>
                        @endif
                    </div>
                </div>

                {{-- RIGHT RAIL — shared promos --}}
                <div class="col-lg-3 col-md-12 col-sm-12 col-12">
                    <x-ads.rail />
                </div>
            </div>
        </div>
    </section>
</div>
