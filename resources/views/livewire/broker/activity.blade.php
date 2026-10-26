{{--
    /broker/activity (§11A B.8) — centre column + right rail of the 3/6/3 row opened by layouts.broker.
    The bureau's activity log with staff / date / kind filters. Owner and Manager.
--}}
<div class="broker-page">
    <div class="col-lg-6 col-md-12 col-sm-12 col-12">
        <div class="dashboard-content mb-4">
            <div class="content-header">
                <h1>{{ __('Activity') }}</h1>
                <p>{{ __('Who in your bureau did what, and when.') }}</p>
            </div>

            <div class="managed-list__filters">
                <div>
                    <label for="activityStaff" class="form-label">{{ __('Staff member') }}</label>
                    <select id="activityStaff" name="staff" class="form-select" wire:model.live="staff">
                        <option value="">{{ __('Everyone') }}</option>
                        @foreach ($members as $code => $name)
                            <option value="{{ $code }}">{{ $name }} ({{ $code }})</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="activityGroup" class="form-label">{{ __('Kind') }}</label>
                    <select id="activityGroup" name="group" class="form-select" wire:model.live="group">
                        <option value="">{{ __('All activity') }}</option>
                        @foreach ($groups as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="activityFrom" class="form-label">{{ __('From') }}</label>
                    <input id="activityFrom" name="from" type="date" class="form-control" wire:model.live="from">
                </div>
                <div>
                    <label for="activityTo" class="form-label">{{ __('To') }}</label>
                    <input id="activityTo" name="to" type="date" class="form-control" wire:model.live="to">
                </div>
            </div>
        </div>

        <div class="dashboard-content" wire:loading.class="is-loading" wire:target="staff, group, from, to, gotoPage, nextPage, previousPage">
            @if ($rows->isEmpty())
                <x-ui.empty-state icon="fa-history" :title="__('No activity found')"
                    :message="__('Profiles added, interests answered, exports and staff changes appear here.')" />
            @else
                <ul class="activity-log">
                    @foreach ($rows as $row)
                        <li class="activity-log__item" wire:key="activity-{{ $row->id }}">
                            <span>
                                <strong>{{ $names[$row->actor_id] ?? __('Former staff') }}</strong>
                                {{ \App\Queries\Broker\BureauActivity::label($row->action) }}@if ($row->subject_label) · {{ $row->subject_label }}@endif
                            </span>
                            <span class="activity-log__when">{{ $row->created_at?->timezone(config('oppam.display_timezone'))->format('j M Y, g:i A') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
        <x-ui.pagination :paginator="$rows" :label="__('Activity pages')" />
    </div>

    <div class="col-lg-3 col-md-12 col-sm-12 col-12">
        <div class="side-bar broker-help">
            <h2>{{ __('About the log') }}</h2>
            <p class="small mb-0">{{ __('Every entry names the person who did it. Entries can’t be changed or deleted.') }}</p>
        </div>
    </div>
</div>
