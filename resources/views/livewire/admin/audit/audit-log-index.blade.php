{{--
    A12 audit log (P9.2a): filters (in the URL), the trail newest first (cursor pages), a drawer with
    one row's redacted diff. Read-only.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Audit log') }}</h1>
            <p>{{ __('Every admin change and every sensitive read, newest first. Entries are kept for 7 years and can never be edited or deleted.') }}</p>
        </div>
        @if ($canExport)
            <div>
                <x-ui.button variant="outline" size="sm" type="button" icon="fa-download" wire:click="export" wire:loading.attr="disabled" wire:target="export" dusk="audit-export">
                    {{ __('Export CSV') }}
                </x-ui.button>
            </div>
        @endif
    </div>

    <div class="ui-card mb-3">
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <x-ui.input :label="__('Search')" name="search" id="audit-search" wire:model.live.debounce.400ms="search"
                            :hint="__('Words in the action, who did it, the subject or the reason (3 letters or more).')" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('Who')" name="actor" id="audit-actor" wire:model.live.debounce.400ms="actor"
                            placeholder="admin@… / OPM12345 / system" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('Action or area')" name="action" id="audit-action" wire:model.live.debounce.400ms="action" list="audit-areas" placeholder="billing" />
                <datalist id="audit-areas">
                    @foreach ($areas as $area)
                        <option value="{{ $area }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.select :label="__('Subject type')" name="subjectType" id="audit-subject-type" wire:model.live="subjectType" :options="$subjectTypes" :placeholder="__('Any')" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('Subject code')" name="subject" id="audit-subject" wire:model.live.debounce.400ms="subject" placeholder="OPM12345" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('From (IST)')" name="from" id="audit-from" type="date" wire:model.live="from" />
            </div>
            <div class="col-6 col-lg-2">
                <x-ui.input :label="__('To (IST)')" name="to" id="audit-to" type="date" wire:model.live="to" />
            </div>
        </div>
        <div class="mt-2 d-flex flex-wrap align-items-center gap-2">
            @if ($entity !== '')
                <x-ui.badge variant="muted" icon="fa-filter">{{ __('Timeline of :what', ['what' => strtoupper(\Illuminate\Support\Str::after($entity, ':'))]) }}</x-ui.badge>
                <x-ui.button size="sm" variant="link" type="button" wire:click="$set('entity', '')">{{ __('Show all records') }}</x-ui.button>
            @endif
            <x-ui.button size="sm" variant="ghost" type="button" wire:click="clearFilters" icon="fa-times">{{ __('Clear filters') }}</x-ui.button>
        </div>
    </div>

    @if ($unknown !== [])
        <x-ui.alert type="warning">
            {{ __('Nothing matches :what — check it.', ['what' => collect($unknown)->map(fn (string $k): string => match ($k) { 'actor' => __('"Who"'), 'action' => __('the action'), 'entity' => __('the timeline record'), default => __('the subject type') })->implode(', ')]) }}
        </x-ui.alert>
    @endif
    @if ($timelineCapped)
        <x-ui.alert type="info">{{ __('This record owns more than :cap items of one kind: only the latest :cap of them are included in this timeline (and its export).', ['cap' => $cap]) }}</x-ui.alert>
    @endif
    @if ($ignoredTerms)
        <x-ui.alert type="info">{{ __('Words shorter than 3 letters and common words such as "the", "with" or "for" are left out of the search.') }}</x-ui.alert>
    @endif

    <div wire:loading.class="opacity-50" wire:target="actor,action,subjectType,subject,from,to,search,entity,clearFilters,nextPage,previousPage,setPage">
        <x-admin.table :headers="[__('When (IST)'), __('Who'), __('Action'), __('Subject'), __('Reason'), '']" :caption="__('Audit log')" :empty="$rows->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-history" :title="__('No entries')" :message="__('No audit entry matches these filters.')" />
            </x-slot:emptyState>

            @foreach ($rows as $row)
                <tr wire:key="audit-{{ $row->id }}">
                    <td class="text-nowrap">{{ $row->created_at->timezone($timezone)->format('d M Y, H:i:s') }}</td>
                    <td>{{ $names[$row->id] ?? '' }}</td>
                    <td><code>{{ $row->action }}</code></td>
                    <td>
                        {{ $row->subject_label ?? '—' }}
                        @if ($row->subject_type)
                            <div class="small text-muted-brand">{{ $row->subject_type }}</div>
                        @endif
                    </td>
                    <td class="small">{{ \Illuminate\Support\Str::limit((string) $row->reason, 80) ?: '—' }}</td>
                    <td>
                        <x-ui.button size="sm" variant="ghost" type="button" wire:click="show('{{ $row->id }}')" wire:loading.attr="disabled" wire:target="show"
                                     :aria-label="__('Details of :action at :time', ['action' => $row->action, 'time' => $row->created_at->timezone($timezone)->format('d M Y, H:i:s')])">
                            {{ __('Details') }}
                        </x-ui.button>
                    </td>
                </tr>
            @endforeach
        </x-admin.table>

        <div class="mt-3">{{ $rows->links() }}</div>
    </div>

    <x-admin.drawer name="audit-entry" :title="__('Audit entry')">
        @if ($open)
            <dl class="row small mb-3">
                <dt class="col-4">{{ __('When (IST)') }}</dt>
                <dd class="col-8">{{ $open->created_at->timezone($timezone)->format('d M Y, H:i:s') }}</dd>
                <dt class="col-4">{{ __('Who') }}</dt>
                <dd class="col-8">{{ $names[$open->id] ?? '' }}</dd>
                <dt class="col-4">{{ __('Action') }}</dt>
                <dd class="col-8"><code>{{ $open->action }}</code></dd>
                <dt class="col-4">{{ __('Subject') }}</dt>
                <dd class="col-8">{{ trim(($open->subject_type ?? '').' '.($open->subject_label ?? '')) ?: '—' }}</dd>
                <dt class="col-4">{{ __('Reason') }}</dt>
                <dd class="col-8">{{ $open->reason ?? '—' }}</dd>
                <dt class="col-4">{{ __('IP address') }}</dt>
                <dd class="col-8">{{ $open->getAttribute('ip_address') ?? '—' }}</dd>
                <dt class="col-4">{{ __('Browser') }}</dt>
                <dd class="col-8 text-break">{{ $open->getAttribute('user_agent') ?? '—' }}</dd>
            </dl>
            <h3 class="h6">{{ __('Before and after') }}</h3>
            <x-admin.diff :lines="$lines" />
        @else
            <x-ui.skeleton shape="row" />
        @endif
    </x-admin.drawer>
</div>
