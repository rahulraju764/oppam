{{--
    One A09 report (P9.1a): period + compare, headline numbers, charts (each with a hidden data table),
    breakdown tables, notes; Marketplace adds the "no interest" work queue. Export CSV / Excel.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <p class="mb-1"><a href="{{ route('admin.reports.index') }}" wire:navigate class="small">{{ __('← All reports') }}</a></p>
            <h1>{{ $key->label() }}</h1>
            <p>{{ $key->question() }}</p>
        </div>
        @if ($canExport && $range)
            <div class="d-flex gap-2 flex-wrap">
                <x-ui.button type="button" variant="secondary" size="sm" icon="fa-regular fa-file-lines" wire:click="export('csv')" loading="export" dusk="export-csv">{{ __('Export CSV') }}</x-ui.button>
                <x-ui.button type="button" variant="secondary" size="sm" icon="fa-regular fa-file-excel" wire:click="export('xlsx')" loading="export" dusk="export-xlsx">{{ __('Export Excel') }}</x-ui.button>
            </div>
        @endif
    </div>

    <div class="ui-card mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-4">
                <x-ui.select :label="__('Period')" name="reportPreset" id="report-preset" wire:model.live="preset" :options="$presets" :selected="$preset" />
            </div>
            @if ($preset === 'custom')
                <div class="col-6 col-md-3">
                    <x-ui.input type="date" :label="__('From')" name="reportFrom" id="report-from" wire:model.live.debounce.400ms="from" :max="$maxDate" error="from" />
                </div>
                <div class="col-6 col-md-3">
                    <x-ui.input type="date" :label="__('To')" name="reportTo" id="report-to" wire:model.live.debounce.400ms="to" :max="$maxDate" error="to" />
                </div>
            @endif
            <div class="col-12 col-md-2">
                <x-ui.checkbox name="reportCompare" id="report-compare" wire:model.live="compare">{{ __('Compare with the period before') }}</x-ui.checkbox>
            </div>
        </div>
        @if ($range)
            <p class="small text-muted-brand mt-2 mb-0" aria-live="polite">
                {{ $range->label() }}@if ($compare) · {{ __('compared with :prev', ['prev' => $range->previous()->label()]) }}@endif
            </p>
        @endif
    </div>

    <div wire:loading.class="is-loading" wire:target="preset, from, to, compare, gotoPage, nextPage, previousPage">
        @if (! $data)
            <x-ui.empty-state icon="fa-solid fa-calendar-days" :title="__('Choose a valid period')" :message="__('Pick a start and end date, up to yesterday.')" />
        @else
            <div class="row g-3 mb-3">
                @foreach ($data->stats as $stat)
                    <div class="col-6 col-lg-3" wire:key="stat-{{ $loop->index }}">
                        <x-admin.stat-card :label="$stat->label" :value="$stat->value" :delta="$compare ? $stat->delta : null"
                                           :delta-label="__('against the period before')" :definition="$stat->definition" />
                    </div>
                @endforeach
            </div>

            <div class="row g-3 mb-3">
                @foreach ($data->charts as $chart)
                    {{-- The key carries the period: a new period replaces the canvas so Chart.js draws the new numbers. --}}
                    <div @class(['col-12', 'col-xl-8' => $chart['type'] !== 'doughnut', 'col-xl-4' => $chart['type'] === 'doughnut'])
                         wire:key="chart-{{ $chart['id'] }}-{{ $range->from->toDateString() }}-{{ $range->to->toDateString() }}">
                        <x-ui.card>
                            <h2 class="admin-chart__title" id="chart-{{ $chart['id'] }}-title">{{ $chart['title'] }}</h2>
                            <div class="admin-chart" wire:ignore x-data="adminChart(@js($chart))">
                                <canvas x-ref="canvas" role="img" aria-labelledby="chart-{{ $chart['id'] }}-title"></canvas>
                            </div>
                            {{-- visually-hidden on a wrapper: a <table> ignores the 1 px width and overflowed 320 px screens --}}
                            <div class="visually-hidden">
                                <table>
                                    <caption>{{ $chart['title'] }}</caption>
                                    <thead><tr><th scope="col">{{ __('Label') }}</th>@foreach ($chart['datasets'] as $set)<th scope="col">{{ $set['label'] }}</th>@endforeach</tr></thead>
                                    <tbody>
                                        @foreach ($chart['labels'] as $i => $label)
                                            <tr><th scope="row">{{ $label }}</th>@foreach ($chart['datasets'] as $set)<td>{{ number_format((float) ($set['data'][$i] ?? 0)) }}</td>@endforeach</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </x-ui.card>
                    </div>
                @endforeach
            </div>

            @foreach ($data->tables as $table)
                <h2 class="admin-section-title">{{ $table->title }}</h2>
                <x-admin.table :headers="$table->headers" :caption="$table->title" :empty="$table->rows === []" class="mb-3" wire:key="table-{{ $table->key }}">
                    <x-slot:emptyState>
                        <x-ui.empty-state icon="fa-solid fa-table" :title="__('No data for this period')" :message="__('Numbers appear after the nightly snapshot has run for these days.')" />
                    </x-slot:emptyState>
                    @foreach ($table->rows as $row)
                        <tr>
                            @foreach ($row as $cell)
                                <td @class(['text-end' => is_int($cell) || is_float($cell)])>{{ is_int($cell) || is_float($cell) ? number_format((float) $cell, is_float($cell) ? 1 : 0) : $cell }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </x-admin.table>
            @endforeach

            @if ($queue)
                <h2 class="admin-section-title">{{ __('Live profiles with no interest in :days days', ['days' => $queueDays]) }}</h2>
                <x-admin.table :headers="[__('Profile'), __('Gender'), __('District'), __('Live since')]" :caption="__('No-interest work queue')" :empty="$queue->isEmpty()">
                    <x-slot:emptyState>
                        <x-ui.empty-state icon="fa-solid fa-check" :title="__('Every live profile got an interest recently')" :message="__('Nobody to follow up with right now.')" />
                    </x-slot:emptyState>
                    @foreach ($queue as $profile)
                        <tr wire:key="queue-{{ $profile->code }}">
                            <td>
                                @can('members.view')
                                    <a href="{{ route('admin.members.show', $profile->code) }}" wire:navigate>{{ $profile->code }}</a>
                                @else
                                    {{ $profile->code }}
                                @endcan
                                <span class="small text-muted-brand">{{ $profile->first_name }}</span>
                            </td>
                            <td>{{ $profile->gender?->label() }}</td>
                            <td>{{ $profile->district?->label ?? '—' }}</td>
                            <td class="small">{{ $profile->published_at?->timezone(config('oppam.display_timezone'))->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </x-admin.table>
                {{ $queue->links(data: ['scrollTo' => false]) }}
            @endif

            @if ($data->notes !== [])
                <ul class="small text-muted-brand mt-3">
                    @foreach ($data->notes as $note)<li>{{ $note }}</li>@endforeach
                </ul>
            @endif
        @endif
    </div>
</div>
