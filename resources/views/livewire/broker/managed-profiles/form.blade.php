{{--
    /broker/profiles/create + /broker/profiles/{code}/edit (§11A B.6) — centre column + right rail of the
    3/6/3 row opened by layouts.broker. The client block (mobile + consent), then the six M02 sections as an
    accordion: only the open one is rendered, with the wizard's own step partials. Phone-first.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ $profile ? __('Edit profile :code', ['code' => $profile->code]) : __('Add a client profile') }}</h1>
                <p>{{ __('The same details a member fills in. Our team reviews every profile before it goes live.') }}</p>
            </div>

            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            @if ($rejectionNote)
                <x-ui.alert type="warning">
                    <strong>{{ __('This profile needs changes before it can go live.') }}</strong>
                    <span class="d-block">{{ __('Reviewer’s note: :note', ['note' => $rejectionNote]) }}</span>
                    <span class="d-block">{{ __('Edit the details below and submit again.') }}</span>
                </x-ui.alert>
            @endif

            {{-- The client: mobile (the claim key, B.6) and consent (R-M13-11). --}}
            <section class="managed-form__client" aria-labelledby="client-heading">
                <h2 id="client-heading" class="managed-form__heading">{{ __('Client') }}</h2>

                <div class="form-group">
                    <label for="clientPhone">{{ __('Client mobile number') }} *</label>
                    <input id="clientPhone" name="clientPhone" type="tel" inputmode="tel" maxlength="20" autocomplete="off"
                           wire:model="clientPhone" @class(['form-control', 'is-invalid' => $errors->has('clientPhone')])
                           aria-describedby="clientPhone-hint @error('clientPhone') clientPhone-error @enderror"
                           placeholder="{{ __('e.g. 98765 43210') }}">
                    <small id="clientPhone-hint" class="form-text">
                        {{ __('The client’s own number. When they join Oppam with it, they can take over this profile.') }}
                    </small>
                    @error('clientPhone') <p class="invalid-feedback d-block" id="clientPhone-error" role="alert">{{ $message }}</p> @enderror
                    @if ($profile)
                        <x-ui.button type="button" variant="outline" size="sm" class="mt-2" wire:click="saveClientPhone" loading="saveClientPhone">
                            {{ __('Save mobile') }}
                        </x-ui.button>
                    @endif
                </div>

                @if (! $profile)
                    <x-ui.checkbox name="consent" id="consent" wire:model="consent">
                        {{ __('I confirm the client has agreed to create this profile and to Oppam Matrimony’s Terms & Privacy Policy.') }}
                    </x-ui.checkbox>
                @else
                    <p class="form-text mb-0">
                        <i class="fa fa-check-circle" aria-hidden="true"></i>
                        {{ __('Client consent confirmed on :date.', ['date' => $profile->broker_consent_confirmed_at?->timezone(config('oppam.display_timezone'))->format('j M Y')]) }}
                    </p>
                @endif
            </section>
        </div>

        {{-- The M02 sections. Before the draft exists only "Basic" is open. --}}
        <div class="managed-form" x-data="wizardAutosave(20000)" x-on:input="touch()" x-on:change="touch()">
            @foreach ($steps as $item)
                @php($isOpen = $item === $current)
                @php($canOpen = $progress?->canOpen($item) ?? $item === \App\Enums\WizardStep::Basic)
                <section class="dashboard-content managed-form__section mb-3" wire:key="section-{{ $item->value }}">
                    <h2 class="managed-form__heading">
                        <button type="button" class="managed-form__toggle" id="section-{{ $item->value }}-toggle"
                                aria-expanded="{{ $isOpen ? 'true' : 'false' }}" aria-controls="section-{{ $item->value }}"
                                wire:click="open({{ $item->value }})" wire:loading.attr="disabled" wire:target="open"
                                @disabled(! $canOpen || $isOpen)>
                            <span class="managed-form__step">
                                @if ($progress?->isComplete($item))
                                    <i class="fa fa-check" aria-hidden="true"></i><span class="visually-hidden">{{ __('Done:') }}</span>
                                @else
                                    {{ $item->value }}
                                @endif
                            </span>
                            {{ $item->title() }}
                            @if (! $canOpen)
                                <small class="managed-form__locked">{{ $profile ? __('Finish the earlier sections first') : __('Save the draft first') }}</small>
                            @endif
                        </button>
                    </h2>

                    @if ($isOpen)
                        <div id="section-{{ $item->value }}" role="region" aria-labelledby="section-{{ $item->value }}-toggle">
                            <form wire:submit="save" novalidate>
                                @switch($item)
                                    @case(\App\Enums\WizardStep::Basic)
                                        @include('livewire.member.onboarding.steps.basic')
                                        @break
                                    @case(\App\Enums\WizardStep::Career)
                                        @include('livewire.member.onboarding.steps.career')
                                        @break
                                    @case(\App\Enums\WizardStep::Family)
                                        @include('livewire.member.onboarding.steps.family')
                                        @break
                                    @case(\App\Enums\WizardStep::Preferences)
                                        @include('livewire.member.onboarding.steps.partner')
                                        @break
                                    @case(\App\Enums\WizardStep::Contact)
                                        @include('livewire.member.onboarding.steps.contact')
                                        @break
                                    @case(\App\Enums\WizardStep::Photos)
                                        @include('livewire.member.onboarding.steps.photos')
                                        @break
                                @endswitch

                                <div class="wizard-actions">
                                    <p class="form-text mb-0" aria-live="polite">
                                        <span wire:loading wire:target="autosave">{{ __('Saving…') }}</span>
                                        @if ($savedAt)
                                            <span wire:loading.remove wire:target="autosave">{{ __('Saved at :time', ['time' => $savedAt]) }}</span>
                                        @endif
                                    </p>
                                    <button type="submit" class="view-btn" wire:loading.attr="disabled" wire:target="save">
                                        <span class="ui-spinner" wire:loading wire:target="save" aria-hidden="true"></span>
                                        @if (! $profile)
                                            {{ __('Save draft') }}
                                        @elseif ($item->next())
                                            {{ __('Save & continue') }} <i class="fa fa-arrow-right" aria-hidden="true"></i>
                                        @else
                                            {{ __('Save') }} <i class="fa fa-check" aria-hidden="true"></i>
                                        @endif
                                    </button>
                                </div>
                            </form>
                        </div>
                    @endif
                </section>
            @endforeach

            @error('submit')
                <x-ui.alert type="danger">{{ $message }}</x-ui.alert>
            @enderror

            @if ($canSubmit)
                <div class="dashboard-content text-center">
                    <p class="form-text">{{ __('When every section is done, send the profile to our team. It goes live only after approval.') }}</p>
                    <button type="button" class="view-btn" wire:click="submit" wire:loading.attr="disabled" wire:target="submit"
                            @disabled(! ($progress?->isReadyToSubmit() ?? false))>
                        <span class="ui-spinner" wire:loading wire:target="submit" aria-hidden="true"></span>
                        {{ __('Submit for review') }} <i class="fa fa-check" aria-hidden="true"></i>
                    </button>
                </div>
            @endif
        </div>
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ $profile ? $profile->code : __('New profile') }}</h2>
            @if ($profile)
                <p class="mb-2"><x-ui.badge :variant="$profile->status->badgeVariant()">{{ $profile->status->label() }}</x-ui.badge></p>
                @if ($progress)
                    <div class="wizard-progress mb-3" role="progressbar" aria-label="{{ __('Sections completed') }}"
                         aria-valuenow="{{ $progress->percent() }}" aria-valuemin="0" aria-valuemax="100">
                        <span class="wizard-progress-bar" style="--progress: {{ $progress->percent() }}%"></span>
                    </div>
                @endif
                <p class="mb-0"><a href="{{ route('broker.profiles.show', ['code' => $profile->code]) }}" wire:navigate>{{ __('Back to the profile') }}</a></p>
            @else
                <ul class="broker-help__steps">
                    <li>{{ __('Enter the client’s mobile and confirm their consent.') }}</li>
                    <li>{{ __('Fill in the basic details and save the draft.') }}</li>
                    <li>{{ __('Complete the other sections, add photos, then submit for review.') }}</li>
                </ul>
            @endif
        </div>
    </div>
</div>
