{{-- /onboarding/{step} — profile wizard (M02). Template profile-creation.php … family.php markup:
     .register-wrapper with the step rail (left) and the step form (right). --}}
<div>
    <h1 class="visually-hidden">{{ __('Create Your Profile') }}</h1>

    <section class="register-section">
        <div class="container is-readable">
            <div class="register-heading">
                <h2 class="profile-title">{{ $current->title() }}</h2>
                <p class="profile-subtitle">{{ __('Create your profile and begin your journey to find your perfect life partner.') }}</p>
            </div>

            @if ($profile?->status === \App\Enums\ProfileStatus::Rejected)
                <x-ui.alert type="warning">{{ __('Your profile needs changes before it can go live. Update the details and submit again.') }}</x-ui.alert>
            @endif

            <div class="register-wrapper">
                {{-- Left: step rail. Links only to steps that may be opened (earlier steps done). --}}
                <div class="register-left">
                    <div class="dashboard-title">
                        <h3>{{ __('Create Profile') }}</h3>
                        <p>{{ __('Complete all steps to build your profile') }}</p>
                    </div>

                    @if ($progress)
                        <div class="wizard-progress" role="progressbar" aria-label="{{ __('Profile steps completed') }}"
                             aria-valuenow="{{ $progress->percent() }}" aria-valuemin="0" aria-valuemax="100">
                            <span class="wizard-progress-bar" style="--progress: {{ $progress->percent() }}%"></span>
                        </div>
                    @endif

                    <nav class="profile-steps" aria-label="{{ __('Profile steps') }}">
                        @foreach ($steps as $item)
                            @php($open = $progress?->canOpen($item) ?? false)
                            @if ($open)
                                <a href="{{ route('member.onboarding', ['step' => $item->value]) }}" wire:navigate
                                   @class(['step-item', 'active' => $item === $current, 'is-done' => $progress?->isComplete($item)])
                                   @if ($item === $current) aria-current="step" @endif>
                            @else
                                <div class="step-item is-locked" aria-disabled="true">
                            @endif
                                <span class="step-number">
                                    @if ($progress?->isComplete($item))
                                        <i class="fa fa-check" aria-hidden="true"></i><span class="visually-hidden">{{ __('Done:') }}</span>
                                    @else
                                        {{ $item->value }}
                                    @endif
                                </span>
                                <div>
                                    <h4>{{ $item->title() }}</h4>
                                    <p>{{ $item->subtitle() }}</p>
                                </div>
                            @if ($open)</a>@else</div>@endif
                        @endforeach
                    </nav>
                </div>

                {{-- Right: the step form. Continue = validate + save + next; 20 s idle = autosave. --}}
                <div class="register-right">
                    <form wire:submit="next" novalidate
                          x-data="wizardAutosave(20000)" x-on:input="touch()" x-on:change="touch()">

                        @switch($current)
                            @case(\App\Enums\WizardStep::Basic)
                                @include('livewire.member.onboarding.steps.basic')
                                @break
                            @case(\App\Enums\WizardStep::Career)
                                @include('livewire.member.onboarding.steps.career')
                                @break
                            @case(\App\Enums\WizardStep::Family)
                                @include('livewire.member.onboarding.steps.family')
                                @break
                            @default
                                <x-ui.alert type="info">{{ __('Your details so far are saved. This step opens very soon.') }}</x-ui.alert>
                        @endswitch

                        <div class="wizard-actions">
                            <p class="form-text mb-0" aria-live="polite">
                                <span wire:loading wire:target="autosave">{{ __('Saving…') }}</span>
                                @if ($savedAt)
                                    <span wire:loading.remove wire:target="autosave">{{ __('Saved at :time', ['time' => $savedAt]) }}</span>
                                @endif
                            </p>

                            <div class="row">
                                <div class="col-12 text-center">
                                    @if ($current->previous())
                                        {{-- Back keeps what was typed (partial save) before leaving the step. --}}
                                        <button type="button" class="wizard-back" wire:click="previous" wire:loading.attr="disabled" wire:target="previous">
                                            <i class="fa fa-arrow-left" aria-hidden="true"></i> {{ __('Back') }}
                                        </button>
                                    @endif
                                    @if ($current->value <= \App\Enums\WizardStep::Family->value)
                                        <button type="submit" class="view-btn" wire:loading.attr="disabled" wire:target="next">
                                            <span class="ui-spinner" wire:loading wire:target="next" aria-hidden="true"></span>
                                            {{ __('continue') }} <i class="fa fa-arrow-right" aria-hidden="true"></i>
                                        </button>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>
