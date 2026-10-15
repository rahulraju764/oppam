{{--
    Admin dashboard (PRD A02): alert strip · KPI row (snapshots, 30-day change, definition, "as of")
    · work-queue cards (count + oldest item, live on admin.queues) · charts (Chart.js through the
    `adminChart` Alpine wrapper, each with a data table for screen readers) · recent activity.
    Sections appear only for the permission that covers them; only data that exists is shown.
--}}
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Welcome, :name', ['name' => auth('admin')->user()->name]) }}</h1>
            @if ($canView)
                <p>
                    @if ($asOf)
                        {{ __('Figures as of :time IST — updated every hour.', ['time' => $asOf->format('d M Y, g:i a')]) }}
                    @else
                        {{ __('The first figures appear after the next hourly update.') }}
                    @endif
                </p>
            @endif
        </div>
    </div>

    {{-- Alerts: server-measured ones, and the browser's own "live connection lost". --}}
    <div class="admin-alerts" aria-live="polite">
        @foreach ($alerts as $alert)
            <x-ui.alert :type="$alert['level']">{{ $alert['text'] }}</x-ui.alert>
        @endforeach
        <div x-data x-show="$store.adminLive && $store.adminLive.status === 'down'" x-cloak>
            <x-ui.alert type="warning">{{ __('Live updates are not connected — counts on this page may be out of date until it reconnects.') }}</x-ui.alert>
        </div>
    </div>

    @if ($canView)
        <h2 class="admin-section-title">{{ __('Key figures') }}</h2>
        <div class="row g-3">
            @foreach ($kpis as $kpi)
                <div class="col-6 col-md-4 col-xl-3" wire:key="kpi-{{ $kpi['metric']->value }}">
                    <x-admin.stat-card :label="$kpi['metric']->label()" :value="$kpi['value'] === null ? '—' : number_format($kpi['value'])"
                                       :delta="$kpi['deltaLabel']" :definition="$kpi['metric']->definition()"
                                       :tick="$kpi['metric'] === \App\Enums\Metric::NewMembers ? $kpi['metric']->value : null" />
                </div>
            @endforeach
            <div class="col-6 col-md-4 col-xl-3" wire:key="kpi-online">
                <x-admin.stat-card :label="__('Online now')" :value="$online === null ? '—' : number_format($online)"
                                   :definition="__('Members connected right now who show their online status.')"
                                   :hint="$online === null ? __('Live server not reachable') : null" />
            </div>
        </div>
    @endif

    @if ($queues !== [])
        <h2 class="admin-section-title">{{ __('Work queues') }}</h2>
        <div class="row g-3">
            @foreach ($queues as $queue)
                <div class="col-sm-6 col-xl-3" wire:key="queue-{{ $queue->routeName }}">
                    <a href="{{ route($queue->routeName) }}" @class(['admin-queue-card', 'is-breached' => $queue->breached])>
                        <span class="admin-queue-card__label"><i class="fa {{ $queue->icon }}" aria-hidden="true"></i> {{ $queue->label }}</span>
                        <span class="admin-queue-card__count" @if ($queue->key) data-queue-count="{{ $queue->key }}" @endif>{{ number_format($queue->count) }}</span>
                        <span class="admin-queue-card__age">
                            @if ($queue->oldest)
                                {{ __('Oldest waiting :age', ['age' => $queue->oldestAge()]) }}
                                @if ($queue->breached)<x-ui.badge variant="warning">{{ __('Past target') }}</x-ui.badge>@endif
                            @else
                                {{ __('Nothing waiting') }}
                            @endif
                        </span>
                    </a>
                </div>
            @endforeach
        </div>
    @endif

    @if ($canView)
        <h2 class="admin-section-title">{{ __('Trends') }}</h2>
        <div class="row g-3">
            @foreach ($charts as $chart)
                <div @class(['col-12', 'col-xl-5' => $chart['type'] !== 'doughnut', 'col-xl-2' => $chart['type'] === 'doughnut']) wire:key="chart-{{ $chart['id'] }}">
                    <x-ui.card>
                        <h3 class="admin-chart__title" id="chart-{{ $chart['id'] }}-title">{{ $chart['title'] }}</h3>
                        {{-- wire:ignore: Chart.js owns the canvas; a re-render must not replace it. --}}
                        <div class="admin-chart" wire:ignore x-data="adminChart(@js($chart))">
                            <canvas x-ref="canvas" role="img" aria-labelledby="chart-{{ $chart['id'] }}-title"></canvas>
                        </div>
                        <table class="visually-hidden">
                            <caption>{{ $chart['title'] }}</caption>
                            <thead>
                                <tr>
                                    <th scope="col">{{ __('Day') }}</th>
                                    @foreach ($chart['datasets'] as $set)<th scope="col">{{ $set['label'] }}</th>@endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($chart['labels'] as $i => $label)
                                    <tr>
                                        <th scope="row">{{ $label }}</th>
                                        @foreach ($chart['datasets'] as $set)<td>{{ number_format($set['data'][$i] ?? 0) }}</td>@endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </x-ui.card>
                </div>
            @endforeach
        </div>

        @if ($activity !== null)
            <h2 class="admin-section-title">{{ __('Recent activity') }}</h2>
            <x-admin.table :headers="[__('When (IST)'), __('Who'), __('Action'), __('On')]" :caption="__('Latest audited actions')" :empty="$activity->isEmpty()">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-history" :title="__('Nothing recorded yet')" />
                </x-slot:emptyState>
                @foreach ($activity as $entry)
                    <tr wire:key="audit-{{ $entry->id }}">
                        <td>{{ $entry->created_at->timezone($timezone)->format('d M, g:i a') }}</td>
                        <td>{{ $entry->actor_label ?? $entry->actor_type->label() }}</td>
                        <td><code>{{ $entry->action }}</code></td>
                        <td>{{ $entry->subject_label ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
        @endif
    @elseif ($queues === [])
        <x-ui.card>
            <x-ui.empty-state icon="fa-compass" :title="__('Your tools are in the menu')" :message="__('Use the sidebar to reach the areas your role gives you.')" />
        </x-ui.card>
    @endif
</div>
