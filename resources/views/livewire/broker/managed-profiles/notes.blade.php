{{--
    /broker/profiles/{code}/notes (§11A B.6) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Add a note / call / meeting with an optional follow-up; the timeline below. Internal to the bureau.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Notes & follow-ups') }}</h1>
                <p>{{ $profile->fullName() }} · {{ $profile->code }}</p>
            </div>

            <form wire:submit="add" novalidate>
                <div class="row">
                    <div class="col-md-6 col-12">
                        <div class="form-group">
                            <label for="noteType">{{ __('Type') }} *</label>
                            <select id="noteType" name="type" wire:model="type" @class(['form-select', 'is-invalid' => $errors->has('type')])>
                                @foreach ($types as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('type') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div class="col-md-6 col-12">
                        <div class="form-group">
                            <label for="noteOutcome">{{ __('Outcome') }}</label>
                            <input id="noteOutcome" name="outcome" type="text" maxlength="40" wire:model="outcome"
                                   @class(['form-control', 'is-invalid' => $errors->has('outcome')]) placeholder="{{ __('e.g. Family interested') }}">
                            @error('outcome') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="noteBody">{{ __('Note') }} *</label>
                    <textarea id="noteBody" name="body" rows="3" maxlength="{{ \App\Actions\Broker\ManagedProfiles\AddClientNote::BODY_MAX }}"
                              wire:model="body" @class(['form-control', 'is-invalid' => $errors->has('body')])
                              @error('body') aria-invalid="true" aria-describedby="noteBody-error" @enderror></textarea>
                    @error('body') <p class="invalid-feedback d-block" id="noteBody-error" role="alert">{{ $message }}</p> @enderror
                </div>

                <div class="form-group">
                    <label for="noteFollowUp">{{ __('Follow up on') }}</label>
                    <input id="noteFollowUp" name="followUpOn" type="date" min="{{ $today }}" wire:model="followUpOn"
                           @class(['form-control', 'is-invalid' => $errors->has('follow_up_on')]) aria-describedby="noteFollowUp-hint">
                    <small id="noteFollowUp-hint" class="form-text">{{ __('Optional. You’ll get a reminder at 8:30 am that day.') }}</small>
                    @error('follow_up_on') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                </div>

                <x-ui.button loading="add" icon="fa-plus">{{ __('Add note') }}</x-ui.button>
            </form>
        </div>

        <div class="dashboard-content" wire:loading.class="is-loading" wire:target="gotoPage, nextPage, previousPage, complete">
            <h2 class="managed-form__heading">{{ __('History') }}</h2>
            @if ($notes->isEmpty())
                <x-ui.empty-state icon="fa-sticky-note-o" :title="__('No notes yet')"
                    :message="__('Record calls and meetings with the family, and set a date to follow up.')" />
            @else
                <ul class="client-notes">
                    @foreach ($notes as $note)
                        <li class="client-notes__item" wire:key="note-{{ $note->id }}">
                            <div class="client-notes__meta">
                                <x-ui.badge variant="muted">{{ $note->type->label() }}</x-ui.badge>
                                <span>{{ $note->created_at?->timezone(config('oppam.display_timezone'))->format('j M Y, g:i A') }}</span>
                                <span>{{ $authors[$note->author_user_id] ?? __('Former staff') }}</span>
                                @if ($note->outcome)
                                    <span>· {{ $note->outcome }}</span>
                                @endif
                            </div>
                            <p class="client-notes__body">{{ $note->body }}</p>
                            @if ($note->follow_up_on)
                                <div class="client-notes__follow">
                                    <i class="fa fa-bell-o" aria-hidden="true"></i>
                                    {{ __('Follow up on :date', ['date' => $note->follow_up_on->format('j M Y')]) }}
                                    @if ($note->follow_up_done_at)
                                        <x-ui.badge variant="success">{{ __('Done') }}</x-ui.badge>
                                    @else
                                        <x-ui.button type="button" size="sm" variant="outline" wire:click="complete('{{ $note->id }}')" loading="complete">
                                            {{ __('Mark done') }}
                                        </x-ui.button>
                                    @endif
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <x-ui.pagination :paginator="$notes" :label="__('Note pages')" />
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('About notes') }}</h2>
            <p class="small">{{ __('Notes are for your bureau only — members never see them.') }}</p>
            <p class="mb-0"><a href="{{ route('broker.profiles.show', ['code' => $profile->code]) }}" wire:navigate>{{ __('Back to the profile') }}</a></p>
        </div>
    </div>
</div>
