{{--
    A13 conversation overview (PRD A13): metadata only — counts and member codes, never message text.
    The chart uses the admin `adminChart` wrapper with a data table for screen readers.
--}}
@php($member = fn (string $code) => $canViewMembers && str_starts_with($code, 'OPM') ? route('admin.members.show', ['profile' => $code]) : null)
<div>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('Conversation overview') }}</h1>
            <p>{{ __('How members use chat — numbers only. Messages are never shown here.') }}</p>
        </div>
        <div>
            <span class="small text-muted d-block">{{ __('As of :time', ['time' => $data->asOf->setTimezone(config('oppam.display_timezone'))->format('d M, H:i')]) }}</span>
            <x-ui.button size="sm" variant="outline" type="button" icon="fa-refresh" wire:click="refreshFigures" loading="refreshFigures">{{ __('Refresh') }}</x-ui.button>
        </div>
    </div>

    <x-ui.card>
        <h2 class="admin-chart__title" id="chart-{{ $chart['id'] }}-title">{{ $chart['title'] }}</h2>
        {{-- wire:ignore: Chart.js owns the canvas; a re-render must not replace it. --}}
        <div class="admin-chart" wire:ignore x-data="adminChart(@js($chart))">
            <canvas x-ref="canvas" role="img" aria-labelledby="chart-{{ $chart['id'] }}-title"></canvas>
        </div>
        <table class="visually-hidden">
            <caption>{{ $chart['title'] }}</caption>
            <thead><tr><th scope="col">{{ __('Day') }}</th><th scope="col">{{ __('Messages') }}</th></tr></thead>
            <tbody>
                @foreach ($chart['labels'] as $i => $label)
                    <tr><th scope="row">{{ $label }}</th><td>{{ number_format($chart['datasets'][0]['data'][$i] ?? 0) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </x-ui.card>

    <div class="row g-4">
        <div class="col-xl-6">
            <h2 class="admin-section-title">{{ __('Top senders (7 days)') }}</h2>
            <x-admin.table :headers="[__('Member'), __('Messages'), __('Conversations')]" :caption="__('Members who sent the most messages in the last 7 days')" :empty="$data->topSenders === []">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-comments-o" :title="__('No messages in the last 7 days')" />
                </x-slot:emptyState>
                @foreach ($data->topSenders as $row)
                    <tr wire:key="top-{{ $row['code'] }}">
                        <td>@if ($member($row['code']))<a href="{{ $member($row['code']) }}" wire:navigate>{{ $row['code'] }}</a>@else{{ $row['code'] }}@endif</td>
                        <td>{{ number_format($row['messages']) }}</td>
                        <td>{{ number_format($row['threads']) }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
        </div>

        <div class="col-xl-6">
            <h2 class="admin-section-title">{{ __('One-sided conversations') }}</h2>
            <p class="small text-muted">{{ __(':count or more messages in a row without a reply — a possible harassment signal.', ['count' => $data->oneSidedThreshold]) }}</p>
            <x-admin.table :headers="[__('Writing'), __('Not replying'), __('In a row'), __('Last message (IST)')]" :caption="__('Conversations where one member keeps writing without a reply')" :empty="$data->oneSided === []">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-check" :title="__('No one-sided conversations')" />
                </x-slot:emptyState>
                @foreach ($data->oneSided as $i => $row)
                    <tr wire:key="one-sided-{{ $i }}">
                        <td>@if ($member($row['sender']))<a href="{{ $member($row['sender']) }}" wire:navigate>{{ $row['sender'] }}</a>@else{{ $row['sender'] }}@endif</td>
                        <td>{{ $row['recipient'] }}</td>
                        <td>{{ number_format($row['unanswered']) }}</td>
                        <td>{{ $row['last_at'] ?? '—' }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
        </div>

        <div class="col-12">
            <h2 class="admin-section-title">{{ __('New accounts messaging many members (24 hours)') }}</h2>
            <p class="small text-muted">{{ __('Accounts that are a few days old and wrote in :count or more conversations in the last day.', ['count' => $data->newAccountThreads]) }}</p>
            <x-admin.table :headers="[__('Member'), __('Joined'), __('Conversations (24 h)')]" :caption="__('New accounts writing in many conversations')" :empty="$data->newAccounts === []">
                <x-slot:emptyState>
                    <x-ui.empty-state icon="fa-check" :title="__('No new accounts are messaging many members')" />
                </x-slot:emptyState>
                @foreach ($data->newAccounts as $row)
                    <tr wire:key="new-{{ $row['code'] }}">
                        <td>@if ($member($row['code']))<a href="{{ $member($row['code']) }}" wire:navigate>{{ $row['code'] }}</a>@else{{ $row['code'] }}@endif</td>
                        <td>{{ $row['joined'] }}</td>
                        <td>{{ number_format($row['threads']) }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
        </div>
    </div>
</div>
