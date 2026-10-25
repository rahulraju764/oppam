{{--
    /broker/profiles/{code} (§11A B.6) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Summary, moderation status / reason, and the actions the caller's role allows.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ $profile->fullName() }}</h1>
                <p>
                    {{ $profile->code }} ·
                    @if ($deleted)
                        <x-ui.badge variant="muted">{{ __('Deleted') }}</x-ui.badge>
                    @else
                        <x-ui.badge :variant="$profile->status->badgeVariant()">{{ $profile->status->label() }}</x-ui.badge>
                    @endif
                </p>
            </div>

            @if (session('status'))
                <x-ui.alert type="success">{{ session('status') }}</x-ui.alert>
            @endif

            @if ($deleted)
                <x-ui.alert type="warning">
                    {{ __('This profile is deleted and no longer visible to anyone. It can be restored until :date; after that it is erased.', ['date' => $restoreUntil?->timezone(config('oppam.display_timezone'))->format('j M Y')]) }}
                </x-ui.alert>
            @elseif ($profile->status === \App\Enums\ProfileStatus::PendingReview)
                <x-ui.alert type="info">{{ __('Our team is reviewing this profile. You’ll be told as soon as they decide.') }}</x-ui.alert>
            @elseif ($profile->status === \App\Enums\ProfileStatus::Rejected)
                <x-ui.alert type="warning">
                    <strong>{{ __('This profile needs changes before it can go live.') }}</strong>
                    @if ($decisionNote)
                        <span class="d-block">{{ __('Reviewer’s note: :note', ['note' => $decisionNote]) }}</span>
                    @endif
                </x-ui.alert>
            @elseif ($profile->status === \App\Enums\ProfileStatus::Hidden)
                <x-ui.alert type="info">{{ __('Deactivated: the profile doesn’t appear in search until it is activated again.') }}</x-ui.alert>
            @endif

            <dl class="managed-detail__facts">
                <div><dt>{{ __('Gender') }}</dt><dd>{{ $profile->gender->label() }}</dd></div>
                <div><dt>{{ __('Age') }}</dt><dd>{{ $profile->age() !== null ? __(':age yrs', ['age' => $profile->age()]) : '—' }}</dd></div>
                <div><dt>{{ __('Religion') }}</dt><dd>{{ $profile->religion?->label ?? '—' }}</dd></div>
                <div><dt>{{ __('Caste') }}</dt><dd>{{ $profile->caste?->label ?? '—' }}</dd></div>
                <div><dt>{{ __('Mother tongue') }}</dt><dd>{{ $profile->motherTongue?->label ?? '—' }}</dd></div>
                <div><dt>{{ __('Client mobile') }}</dt><dd>{{ $maskedPhone ?? '—' }}</dd></div>
                <div><dt>{{ __('Completeness') }}</dt><dd>{{ $progress->percent() }} %</dd></div>
                <div><dt>{{ __('Assigned to') }}</dt><dd>{{ $profile->assignedStaff?->displayName() ?? '—' }}</dd></div>
            </dl>
        </div>

        <div class="dashboard-content mb-4">
            <h2 class="managed-form__heading">{{ __('Actions') }}</h2>
            <div class="managed-detail__actions">
                @if ($canEdit)
                    <a href="{{ route('broker.profiles.edit', ['code' => $profile->code]) }}" wire:navigate class="ui-btn ui-btn--primary">
                        <i class="fa fa-pencil" aria-hidden="true"></i> {{ __('Edit details') }}
                    </a>
                @endif
                @if ($canSubmit)
                    <x-ui.button type="button" variant="secondary" icon="fa-paper-plane" wire:click="submit" loading="submit" :disabled="! $progress->isReadyToSubmit()">
                        {{ __('Submit for review') }}
                    </x-ui.button>
                @endif
                @if ($canNotes)
                    <a href="{{ route('broker.profiles.notes', ['code' => $profile->code]) }}" wire:navigate class="ui-btn ui-btn--outline">
                        <i class="fa fa-sticky-note-o" aria-hidden="true"></i> {{ __('Notes & follow-ups') }}
                    </a>
                @endif
                @if ($canInbox)
                    <a href="{{ route('broker.profiles.inbox', ['code' => $profile->code]) }}" wire:navigate class="ui-btn ui-btn--outline">
                        <i class="fa fa-inbox" aria-hidden="true"></i> {{ __('Client inbox') }}
                        @if ($pendingInterests > 0)
                            <x-ui.badge variant="primary">{{ $pendingInterests }}<span class="visually-hidden"> {{ __('pending') }}</span></x-ui.badge>
                        @endif
                    </a>
                @endif
                @foreach ($actions as $action)
                    <div x-data="{ confirming: false }" wire:key="action-{{ $action->value }}">
                        <x-ui.button type="button" :variant="$action === \App\Enums\ManagedProfileStatusChange::Delete ? 'danger' : 'outline'"
                            x-show="! confirming" x-on:click="confirming = true">
                            {{ $action->label() }}
                        </x-ui.button>
                        <span x-show="confirming" x-cloak class="d-inline-flex flex-wrap align-items-center gap-2">
                            <span class="form-text">{{ __('Sure?') }}</span>
                            <x-ui.button type="button" size="sm" :variant="$action === \App\Enums\ManagedProfileStatusChange::Delete ? 'danger' : 'primary'"
                                wire:click="change('{{ $action->value }}')" loading="change" x-on:click="confirming = false">
                                {{ __('Yes, :action', ['action' => mb_strtolower($action->label())]) }}
                            </x-ui.button>
                            <x-ui.button type="button" size="sm" variant="ghost" x-on:click="confirming = false">{{ __('Cancel') }}</x-ui.button>
                        </span>
                    </div>
                @endforeach
            </div>
            @if (! $canSubmit && ! $canEdit && $actions === [] && ! $canNotes && ! $canInbox)
                <p class="form-text mb-0">{{ __('Your role can view this profile but not change it.') }}</p>
            @endif
        </div>

        @if ($canExport)
            <div class="dashboard-content mb-4">
                <h2 class="managed-form__heading">{{ __('Downloads') }}</h2>
                <p class="form-text">{{ __('Every download is recorded with your name. The biodata carries your bureau’s name on every page.') }}</p>
                <div class="managed-detail__actions">
                    {{-- Plain links (no wire:navigate): the files stream from a controller. --}}
                    <a href="{{ route('broker.profiles.export.biodata', ['code' => $profile->code]) }}" class="ui-btn ui-btn--outline" download>
                        <i class="fa fa-file-pdf-o" aria-hidden="true"></i> {{ __('Biodata PDF') }}
                    </a>
                    @if ($approvedPhotos > 0)
                        <a href="{{ route('broker.profiles.export.photos', ['code' => $profile->code]) }}" class="ui-btn ui-btn--outline" download>
                            <i class="fa fa-file-archive-o" aria-hidden="true"></i> {{ trans_choice('{1} Photo (1)|[2,*] Photos (:count)', $approvedPhotos, ['count' => $approvedPhotos]) }}
                        </a>
                    @else
                        <span class="form-text">{{ __('Photos can be downloaded once our team approves them.') }}</span>
                    @endif
                </div>
            </div>
        @endif

        @if ($canAssign)
            <div class="dashboard-content">
                <h2 class="managed-form__heading">{{ __('Assignment') }}</h2>
                <div class="managed-list__bulk">
                    <div>
                        <label for="profileAssignTo" class="form-label">{{ __('Assigned to') }}</label>
                        <select id="profileAssignTo" name="assignTo" wire:model="assignTo" @class(['form-select', 'is-invalid' => $errors->has('assignTo')])>
                            <option value="">{{ __('Nobody') }}</option>
                            @foreach ($assignees as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                        @error('assignTo') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.button type="button" variant="secondary" wire:click="assign" loading="assign">{{ __('Save') }}</x-ui.button>
                </div>
            </div>
        @endif
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('Sections') }}</h2>
            <ul class="broker-help__steps">
                @foreach (\App\Enums\WizardStep::cases() as $step)
                    <li>
                        @if ($progress->isComplete($step))
                            <i class="fa fa-check" aria-hidden="true"></i><span class="visually-hidden">{{ __('Done:') }}</span>
                        @else
                            <i class="fa fa-circle-o" aria-hidden="true"></i><span class="visually-hidden">{{ __('To do:') }}</span>
                        @endif
                        {{ $step->title() }}
                    </li>
                @endforeach
            </ul>
            <p class="mb-0"><a href="{{ route('broker.profiles.index') }}" wire:navigate>{{ __('All client profiles') }}</a></p>
        </div>
    </div>
</div>
