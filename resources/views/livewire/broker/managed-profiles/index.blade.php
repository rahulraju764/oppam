{{--
    /broker/profiles (§11A B.6 "Manage") — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Filters (#[Url]), the scoped list, bulk assignment for Owners / Managers.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Client profiles') }}</h1>
                <p>{{ __('Profiles your bureau manages for its clients.') }}</p>
            </div>

            <div class="managed-list__filters">
                <div>
                    <label for="managedSearch" class="form-label">{{ __('Search') }}</label>
                    <input id="managedSearch" name="q" type="search" class="form-control" maxlength="60"
                           wire:model.live.debounce.400ms="search" placeholder="{{ __('Name or OPM code') }}">
                </div>
                <div>
                    <label for="managedStatus" class="form-label">{{ __('Status') }}</label>
                    <select id="managedStatus" name="status" class="form-select" wire:model.live="status">
                        <option value="">{{ __('All statuses') }}</option>
                        @foreach ($statuses as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                @if ($showStaffFilter)
                    <div>
                        <label for="managedAssignee" class="form-label">{{ __('Assigned to') }}</label>
                        <select id="managedAssignee" name="staff" class="form-select" wire:model.live="assignee">
                            <option value="">{{ __('Anyone') }}</option>
                            <option value="{{ \App\Queries\Broker\ManagedProfileList::UNASSIGNED }}">{{ __('Not assigned') }}</option>
                            @foreach ($assignees as $id => $name)
                                <option value="{{ $id }}">{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
            </div>
        </div>

        <div class="dashboard-content" wire:loading.class="is-loading" wire:target="search, status, assignee, gotoPage, nextPage, previousPage, assignSelected">
            @if ($profiles->isEmpty())
                <x-ui.empty-state icon="fa-users" :title="$filtered ? __('No profiles match') : __('No client profiles yet')"
                    :message="$filtered ? __('Try another search or filter.') : __('Add your first client — it takes a few minutes and our team reviews it before it goes live.')" />
                @if ($canCreate && ! $filtered)
                    <p class="text-center mb-0"><a href="{{ route('broker.profiles.create') }}" wire:navigate class="view-btn">{{ __('Add a profile') }}</a></p>
                @endif
            @else
                <div class="table-responsive">
                    <table class="table broker-table align-middle mb-0">
                        <caption class="visually-hidden">{{ __('Client profiles') }}</caption>
                        <thead>
                            <tr>
                                @if ($canAssign)
                                    <th scope="col"><span class="visually-hidden">{{ __('Select') }}</span></th>
                                @endif
                                <th scope="col">{{ __('Profile') }}</th>
                                <th scope="col">{{ __('Status') }}</th>
                                <th scope="col">{{ __('Assigned to') }}</th>
                                <th scope="col">{{ __('Updated') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($profiles as $row)
                                <tr wire:key="managed-{{ $row->code }}">
                                    @if ($canAssign)
                                        <td class="managed-list__select">
                                            <input type="checkbox" class="form-check-input" id="select-{{ $row->code }}" name="selected[]"
                                                   value="{{ $row->code }}" wire:model.live="selected"
                                                   aria-label="{{ __('Select :code', ['code' => $row->code]) }}">
                                        </td>
                                    @endif
                                    <td>
                                        <a href="{{ route('broker.profiles.show', ['code' => $row->code]) }}" wire:navigate>{{ $row->fullName() }}</a>
                                        <small class="d-block text-muted-brand">{{ $row->code }} · {{ $row->gender->label() }}@if ($row->age() !== null) · {{ __(':age yrs', ['age' => $row->age()]) }}@endif</small>
                                    </td>
                                    <td>
                                        @if ($row->trashed())
                                            <x-ui.badge variant="muted">{{ __('Deleted') }}</x-ui.badge>
                                        @else
                                            <x-ui.badge :variant="$row->status->badgeVariant()">{{ $row->status->label() }}</x-ui.badge>
                                        @endif
                                    </td>
                                    <td>{{ $row->assignedStaff?->displayName() ?? '—' }}</td>
                                    <td>{{ $row->updated_at?->timezone(config('oppam.display_timezone'))->format('j M Y') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($canAssign)
                    <div class="managed-list__bulk">
                        <div>
                            <label for="assignTo" class="form-label">{{ __('Assign the selected (:count) to', ['count' => count($selected)]) }}</label>
                            <select id="assignTo" name="assignTo" wire:model="assignTo" @class(['form-select', 'is-invalid' => $errors->has('assignTo')])>
                                <option value="">{{ __('Nobody (clear)') }}</option>
                                @foreach ($assignees as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('assignTo') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                            @error('codes') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                        </div>
                        <x-ui.button type="button" variant="secondary" wire:click="assignSelected" loading="assignSelected" :disabled="$selected === []">
                            {{ __('Assign') }}
                        </x-ui.button>
                    </div>
                @endif
            @endif
        </div>
        <x-ui.pagination :paginator="$profiles" :label="__('Client profile pages')" />
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('Managing clients') }}</h2>
            @if ($canCreate)
                <p><a href="{{ route('broker.profiles.create') }}" wire:navigate class="view-btn">{{ __('Add a profile') }}</a></p>
            @endif
            <ul class="broker-help__steps">
                <li>{{ __('Draft: being filled in. Submit it when every section is done.') }}</li>
                <li>{{ __('Pending review: our team is checking it.') }}</li>
                <li>{{ __('Active: live and searchable like any profile.') }}</li>
                <li>{{ __('Rejected: see the reason, edit and submit again.') }}</li>
            </ul>
        </div>
    </div>
</div>
