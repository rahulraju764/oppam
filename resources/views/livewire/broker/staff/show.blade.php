{{--
    /broker/staff/{code} (§11A B.8) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    Switch-offs within the role's ceiling, scope, assigned clients, activity, monthly performance, deactivate.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ $staff->displayName() }}</h1>
                <p>
                    {{ $staff->code }} · {{ $staff->role->label() }} ·
                    <x-ui.badge :variant="match ($staff->status) { \App\Enums\BureauStaffStatus::Active => 'success', \App\Enums\BureauStaffStatus::Invited => 'warning', default => 'muted' }">{{ $staff->status->label() }}</x-ui.badge>
                </p>
            </div>
            <dl class="managed-detail__facts">
                <div><dt>{{ __('Mobile') }}</dt><dd>{{ \App\ValueObjects\PhoneNumber::tryParse((string) $staff->user?->phone)?->masked() ?? '—' }}</dd></div>
                <div><dt>{{ __('Last sign-in') }}</dt><dd>{{ $staff->last_login_at?->timezone(config('oppam.display_timezone'))->format('j M Y, g:i A') ?? '—' }}</dd></div>
                <div><dt>{{ __('Profile access') }}</dt><dd>{{ $staff->effectiveScope()->label() }}</dd></div>
            </dl>
        </div>

        @if ($editable)
            <div class="dashboard-content mb-4">
                <h2 class="managed-form__heading">{{ __('Permissions') }}</h2>
                <p class="form-text">{{ __('Tick what to switch OFF for this person. You can only switch off what their role includes.') }}</p>
                <form wire:submit="save" novalidate>
                    <fieldset class="staff-permissions">
                        <legend class="visually-hidden">{{ __('Switched-off permissions') }}</legend>
                        @foreach ($switchable as $permission)
                            <div class="form-check" wire:key="perm-{{ $permission->value }}">
                                <input class="form-check-input" type="checkbox" id="perm-{{ $permission->value }}" name="disabled[]"
                                       value="{{ $permission->value }}" wire:model="disabled">
                                <label class="form-check-label" for="perm-{{ $permission->value }}">{{ __('Switch off: :permission', ['permission' => $permission->label()]) }}</label>
                            </div>
                        @endforeach
                    </fieldset>
                    @error('disabled') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror

                    <div class="form-group mt-3">
                        <label for="staffScopeEdit">{{ __('Profile access') }}</label>
                        <select id="staffScopeEdit" name="scope" wire:model="scope" @class(['form-select', 'is-invalid' => $errors->has('scope')])>
                            @foreach ($scopes as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('scope') <p class="invalid-feedback d-block" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.button loading="save" icon="fa-check">{{ __('Save') }}</x-ui.button>
                </form>
            </div>
        @elseif ($staff->isOwner())
            <x-ui.alert type="info">{{ __('This is your own login — it always has every permission.') }}</x-ui.alert>
        @endif

        <div class="dashboard-content mb-4" wire:loading.class="is-loading" wire:target="month">
            <div class="staff-performance__head">
                <h2 class="managed-form__heading">{{ __('Performance — :month', ['month' => $monthLabel]) }}</h2>
                <div>
                    <label for="staffMonth" class="visually-hidden">{{ __('Month') }}</label>
                    <input id="staffMonth" name="month" type="month" class="form-control" wire:model.live="month">
                </div>
            </div>
            @if ($figures === null)
                <p class="form-text mb-0">{{ __('No activity in this month.') }}</p>
            @else
                <dl class="managed-detail__facts">
                    <div><dt>{{ __('Profiles created') }}</dt><dd>{{ $figures['created'] }}</dd></div>
                    <div><dt>{{ __('Imported') }}</dt><dd>{{ $figures['imported'] }}</dd></div>
                    <div><dt>{{ __('Approved') }}</dt><dd>{{ $figures['approved'] }}</dd></div>
                    <div><dt>{{ __('Rejection rate') }}</dt><dd>{{ $figures['rejection_rate'] }} %</dd></div>
                    <div><dt>{{ __('Interests handled') }}</dt><dd>{{ $figures['interests'] }}</dd></div>
                    <div><dt>{{ __('Follow-ups completed') }}</dt><dd>{{ $figures['follow_ups'] }}</dd></div>
                </dl>
            @endif
        </div>

        <div class="dashboard-content mb-4">
            <h2 class="managed-form__heading">{{ __('Assigned clients') }}</h2>
            @if ($assigned->isEmpty())
                <p class="form-text mb-0">{{ __('No clients assigned.') }}</p>
            @else
                <ul class="broker-feed">
                    @foreach ($assigned as $client)
                        <li wire:key="client-{{ $client->code }}">
                            <a href="{{ route('broker.profiles.show', ['code' => $client->code]) }}" wire:navigate>{{ $client->fullName() }} · {{ $client->code }}</a>
                            <x-ui.badge :variant="$client->status->badgeVariant()">{{ $client->status->label() }}</x-ui.badge>
                        </li>
                    @endforeach
                </ul>
                <x-ui.pagination :paginator="$assigned" :label="__('Assigned client pages')" />
            @endif
        </div>

        <div class="dashboard-content mb-4">
            <h2 class="managed-form__heading">{{ __('Recent activity') }}</h2>
            @if ($recent->isEmpty())
                <p class="form-text mb-0">{{ __('Nothing yet.') }}</p>
            @else
                <ul class="activity-log">
                    @foreach ($recent as $row)
                        <li class="activity-log__item" wire:key="act-{{ $row->id }}">
                            <span>{{ \App\Queries\Broker\BureauActivity::label($row->action) }}@if ($row->subject_label) · {{ $row->subject_label }}@endif</span>
                            <span class="activity-log__when">{{ $row->created_at?->timezone(config('oppam.display_timezone'))->format('j M, g:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="mb-0 mt-2"><a href="{{ route('broker.activity', ['staff' => $staff->code]) }}" wire:navigate>{{ __('All their activity') }}</a></p>
            @endif
        </div>

        @if ($editable)
            <div class="dashboard-content">
                <h2 class="managed-form__heading">{{ __('Deactivate') }}</h2>
                <p class="form-text">{{ __('They are signed out of every device at once, and their assigned clients return to your unassigned list. Their history stays.') }}</p>
                <form wire:submit="deactivate" novalidate>
                    <div class="form-group">
                        <label for="staffConfirm">{{ __('Type :code to confirm', ['code' => $staff->code]) }}</label>
                        <input id="staffConfirm" name="confirm" type="text" autocomplete="off" wire:model="confirm"
                               @class(['form-control', 'is-invalid' => $errors->has('confirm')])
                               @error('confirm') aria-invalid="true" aria-describedby="staffConfirm-error" @enderror>
                        @error('confirm') <p class="invalid-feedback d-block" id="staffConfirm-error" role="alert">{{ $message }}</p> @enderror
                    </div>
                    <x-ui.button variant="danger" loading="deactivate" icon="fa-ban">{{ __('Deactivate :name', ['name' => $staff->displayName()]) }}</x-ui.button>
                </form>
            </div>
        @endif
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('About permissions') }}</h2>
            <p class="small">{{ __('A role sets the most a person can do. You can switch individual permissions off, never on beyond the role.') }}</p>
            <p class="mb-0"><a href="{{ route('broker.staff.index') }}" wire:navigate>{{ __('All staff') }}</a></p>
        </div>
    </div>
</div>
