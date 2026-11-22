{{--
    A12 system health panel (P9.2b1). Read-only; every figure comes from a cached, rescued reader, so
    a missing source shows "unavailable" instead of breaking the page ($scheduler, $webhooks,
    $delivery and $lag are null when their read failed). Times are IST. Re-reads every
    minute. Alerts and webhook replay arrive in P9.2b2.
--}}
@use('Carbon\CarbonInterface')
@use('Carbon\CarbonInterval')
@use('Illuminate\Support\Js')
@use('Illuminate\Support\Number')
@php
    $tz = config('oppam.display_timezone');
    $at = fn (?CarbonInterface $time): string => $time === null ? '—' : $time->timezone($tz)->format('d M, g:i a');
    $num = fn (?int $value): string => $value === null ? '—' : number_format($value);
    $pct = fn (?float $value): string => $value === null ? '—' : rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    $seconds = fn (?int $value): string => $value === null ? '—' : CarbonInterval::seconds($value)->cascade()->forHumans(['short' => true, 'parts' => 2]);
@endphp
<div wire:poll.60s>
    <div class="admin-page-head">
        <div>
            <h1>{{ __('System health') }}</h1>
            <p>{{ __('Checked :time IST — refreshes every minute.', ['time' => $checkedAt->timezone($tz)->format('g:i:s a')]) }}</p>
        </div>
        @if ($horizonUrl || $pulseUrl)
            <div class="d-flex flex-wrap gap-2">
                @if ($horizonUrl)
                    <x-ui.button :href="$horizonUrl" variant="outline" size="sm" icon="fa-tasks">{{ __('Open Horizon') }}</x-ui.button>
                @endif
                @if ($pulseUrl)
                    <x-ui.button :href="$pulseUrl" variant="outline" size="sm" icon="fa-heartbeat">{{ __('Open Pulse') }}</x-ui.button>
                @endif
            </div>
        @endif
    </div>

    <div class="admin-alerts" aria-live="polite">
        @if ($scheduler !== null && ! $scheduler->running)
            <x-ui.alert type="danger">
                {{ $scheduler->lastRunAt === null
                    ? __('No scheduled task has run yet — the scheduler (schedule:run / schedule:work) does not seem to be running.')
                    : __('No scheduled task has run since :time IST — the scheduler does not seem to be running.', ['time' => $at($scheduler->lastRunAt)]) }}
            </x-ui.alert>
        @endif
        @if ($scheduler !== null && $scheduler->missedCount() > 0)
            <x-ui.alert type="warning">{{ trans_choice('{1} 1 scheduled task missed its last run.|[2,*] :count scheduled tasks missed their last run.', $scheduler->missedCount(), ['count' => $scheduler->missedCount()]) }}</x-ui.alert>
        @endif
        @if ($webhooks !== null && $webhooks->unprocessedCount > 0)
            <x-ui.alert type="warning">{{ trans_choice('{1} 1 payment webhook was received but not processed.|[2,*] :count payment webhooks were received but not processed.', $webhooks->unprocessedCount, ['count' => $webhooks->unprocessedCount]) }}</x-ui.alert>
        @endif
    </div>

    {{-- A12 alerts (P9.2b2) --}}
    <h2 class="admin-section-title">{{ __('Alerts') }}</h2>
    @if ($alerts === null)
        <x-ui.alert type="warning">{{ __('Alerts couldn’t be read right now. The error has been reported; this refreshes every minute.') }}</x-ui.alert>
    @else
        <x-admin.table :headers="[__('Alert'), __('State'), __('Opened (IST)'), __('Resolved (IST)')]" :caption="__('Alerts')" :empty="$alerts->isEmpty()">
            <x-slot:emptyState>
                <x-ui.empty-state icon="fa-check" :title="__('No alerts')" :message="__('Nothing has crossed a threshold in the last :days days. Thresholds are in Settings → Business rules.', ['days' => \App\Livewire\Admin\System\SystemHealth::RESOLVED_SHOWN_DAYS])" />
            </x-slot:emptyState>
            @foreach ($alerts as $alert)
                <tr wire:key="alert-{{ $alert->kind->value }}-{{ $alert->opened_at->getTimestamp() }}">
                    <th scope="row">{{ $alert->kind->label() }}<span class="small text-muted-brand d-block fw-normal">{{ $alert->describe() }}</span></th>
                    <td>
                        @if ($alert->resolved_at === null)
                            <x-ui.badge :variant="$alert->kind->level()">{{ __('Open') }}</x-ui.badge>
                            @if ($alert->clear_since !== null)
                                <span class="small text-muted-brand d-block">{{ __('Clear since :time', ['time' => $at($alert->clear_since)]) }}</span>
                            @endif
                        @else
                            <x-ui.badge variant="success">{{ __('Resolved') }}</x-ui.badge>
                        @endif
                    </td>
                    <td>{{ $at($alert->opened_at) }}</td>
                    <td>{{ $at($alert->resolved_at) }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif

    {{-- Key figures --}}
    <div class="row g-3">
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Failed jobs (24 h)')" :value="number_format($queues->failed24h)"
                               :hint="__(':count in the last hour', ['count' => number_format($queues->failedLastHour)])" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Broadcast lag')" :value="$lag?->lagMs === null ? '—' : number_format($lag->lagMs / 1000, 1).' s'"
                               :definition="__('How long a live update waits on the broadcasts queue before a worker sends it.')"
                               :hint="$lag === null ? __('Unavailable') : ($lag->lagMs === null ? __('Not measured in the last 10 minutes') : ($lag->pending ? __('A measurement is still waiting') : null))" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Live connections')" :value="$reverb->stats === null ? '—' : number_format($reverb->stats->connections)"
                               :hint="! $reverb->configured ? __('Live updates are not set up here') : ($reverb->stats === null ? __('Live server not reachable') : null)" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Missed schedules')" :value="$scheduler === null ? '—' : number_format($scheduler->missedCount())"
                               :hint="$scheduler === null ? __('Unavailable') : ($scheduler->trackingSince === null ? __('No runs recorded yet') : null)" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Error rate (1 h)')" :value="$requests->recorded ? $pct($requests->errorRatePct()) : '—'"
                               :definition="__('Server errors (5xx) as a share of all requests in the last hour.')"
                               :hint="$requests->recorded ? __(':count requests', ['count' => number_format($requests->requests)]) : __('Not recorded (Pulse is off)')" />
        </div>
        <div class="col-6 col-md-4 col-xl-2">
            <x-admin.stat-card :label="__('Response time p95 (1 h)')"
                               :value="! $requests->recorded || ($requests->p95Ms === null && ! $requests->p95Over) ? '—' : ($requests->p95Over ? '> '.number_format($p95Ceiling).' ms' : '≤ '.number_format($requests->p95Ms).' ms')"
                               :definition="__('95 of every 100 requests in the last hour finished within this time.')" />
        </div>
    </div>

    {{-- Queues --}}
    <h2 class="admin-section-title">{{ __('Queues') }}</h2>
    <p class="small text-muted-brand">
        {{ __('Connection: :name.', ['name' => $queues->connection]) }}
        @if ($queues->horizon !== null)
            {{ __('Horizon: :status.', ['status' => __(ucfirst($queues->horizon))]) }}
        @else
            {{ __('Horizon is not used on this connection.') }}
        @endif
    </p>
    <x-admin.table :headers="[__('Queue'), __('Waiting'), __('Waiting for a retry'), __('Oldest waiting'), __('Failed (24 h)')]" :caption="__('Queues')">
        @foreach ($queues->queues as $row)
            <tr wire:key="queue-{{ $row->name }}">
                <th scope="row">{{ $row->name }}</th>
                <td>{{ $num($row->waiting) }}</td>
                <td>{{ $row->retrying === null && $queues->horizon !== null ? __('See Horizon') : $num($row->retrying) }}</td>
                <td>{{ $seconds($row->waitSeconds) }}</td>
                <td>
                    @if ($row->failed24h > 0)
                        <x-ui.badge variant="danger">{{ number_format($row->failed24h) }}</x-ui.badge>
                    @else
                        0
                    @endif
                </td>
            </tr>
        @endforeach
    </x-admin.table>

    {{-- Live updates (Reverb) --}}
    <h2 class="admin-section-title">{{ __('Live updates (Reverb)') }}</h2>
    @if (! $reverb->configured)
        <div class="ui-card"><x-ui.empty-state icon="fa-plug" :title="__('Live updates are not set up here')" :message="__('Broadcasting is not set to Reverb or Pusher on this server, so there is nothing to ask.')" /></div>
    @elseif ($reverb->stats === null)
        <x-ui.alert type="danger">{{ __('The live server did not answer within 2 seconds. Members are not getting live updates until it is back.') }}</x-ui.alert>
    @else
        <div class="row g-3">
            <div class="col-12 col-lg-4">
                <div class="ui-card h-100">
                    <dl class="small mb-0">
                        <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Open connections') }}</dt><dd class="mb-1">{{ number_format($reverb->stats->connections) }}</dd></div>
                        <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Messages sent / second (1 h average)') }}</dt><dd class="mb-1">{{ $reverb->messagesPerSecond === null ? __('Not recorded') : number_format($reverb->messagesPerSecond, 2) }}</dd></div>
                        <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Last restart (detected, 24 h)') }}</dt><dd class="mb-1">{{ $reverb->lastRestartAt === null ? __('None seen') : $at($reverb->lastRestartAt) }}</dd></div>
                    </dl>
                </div>
            </div>
            <div class="col-12 col-lg-8">
                <x-admin.table :headers="[__('Channel type'), __('Channels'), __('Subscribers')]" :caption="__('Channels by type')"
                               :empty="$reverb->stats->channels === []">
                    <x-slot:emptyState>
                        <x-ui.empty-state icon="fa-comments-o" :title="__('No open channels')" :message="__('Nobody is subscribed to a live channel right now.')" />
                    </x-slot:emptyState>
                    @foreach ($channelLabels as $type => $label)
                        @isset($reverb->stats->channels[$type])
                            <tr wire:key="channel-{{ $type }}">
                                <th scope="row">{{ $label }}</th>
                                <td>{{ number_format($reverb->stats->channels[$type]) }}</td>
                                <td>{{ number_format($reverb->stats->subscriptions[$type] ?? 0) }}</td>
                            </tr>
                        @endisset
                    @endforeach
                </x-admin.table>
            </div>
        </div>
    @endif

    {{-- Scheduler --}}
    <h2 class="admin-section-title">{{ __('Scheduled tasks') }}</h2>
    @if ($scheduler === null)
        <x-ui.alert type="warning">{{ __('Scheduled task runs couldn’t be read right now. The error has been reported; this refreshes every minute.') }}</x-ui.alert>
    @else
    <p class="small text-muted-brand">
        {{ $scheduler->trackingSince === null ? __('No runs recorded yet.') : __('Runs recorded since :time IST. A run not started :minutes minutes after it was due counts as missed.', ['time' => $at($scheduler->trackingSince), 'minutes' => $graceMinutes]) }}
        {{ __('For a queued task, "OK" means it was put on its queue — the job itself is in Horizon.') }}
    </p>
    <x-admin.table :headers="[__('Task'), __('Schedule'), __('Last run (IST)'), __('Status'), __('Took'), __('Next due (IST)')]" :caption="__('Scheduled tasks')"
                   :empty="$scheduler->tasks === []">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-clock-o" :title="__('No scheduled tasks')" :message="__('routes/console.php defines no schedule.')" />
        </x-slot:emptyState>
        @foreach ($scheduler->tasks as $task)
            <tr wire:key="task-{{ $loop->index }}">
                <th scope="row"><code>{{ $task->task }}</code></th>
                <td><code>{{ $task->expression }}</code> <span class="small text-muted-brand">{{ $task->timezone }}</span></td>
                <td>{{ $at($task->lastStartedAt) }}</td>
                <td>
                    @if ($task->missed)
                        <x-ui.badge variant="danger">{{ __('Missed') }}</x-ui.badge>
                    @endif
                    @if ($task->lastStatus !== null)
                        <x-ui.badge :variant="$task->lastStatus->badgeVariant()">{{ $task->lastStatus->label() }}</x-ui.badge>
                        @if ($task->lastError)
                            <span class="small text-muted-brand d-block">{{ $task->lastError }}</span>
                        @endif
                    @elseif (! $task->missed)
                        <span class="text-muted-brand">{{ __('Not run yet') }}</span>
                    @endif
                </td>
                <td>{{ $task->lastDurationMs === null ? '—' : number_format($task->lastDurationMs).' ms' }}</td>
                <td>{{ $at($task->nextDueAt) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    @endif

    {{-- Webhooks --}}
    <h2 class="admin-section-title">{{ __('Webhooks (last 24 hours)') }}</h2>
    @if ($webhooks === null)
        <x-ui.alert type="warning">{{ __('Webhook figures couldn’t be read right now. The error has been reported; this refreshes every minute.') }}</x-ui.alert>
    @else
    <x-admin.table :headers="[__('Provider'), __('Accepted'), __('Signature rejected'), __('Body rejected'), __('Errors'), __('Last received (IST)')]" :caption="__('Webhooks by provider')">
        @foreach ($webhooks->providers as $provider)
            <tr wire:key="webhook-{{ $provider->provider->value }}">
                <th scope="row">{{ $provider->provider->label() }}</th>
                <td>{{ number_format($provider->accepted) }}</td>
                <td>
                    @if ($provider->rejectedSignature > 0)
                        <x-ui.badge variant="danger">{{ number_format($provider->rejectedSignature) }}</x-ui.badge>
                    @else
                        0
                    @endif
                </td>
                <td>{{ number_format($provider->rejectedBody) }}</td>
                <td>
                    @if ($provider->errors > 0)
                        <x-ui.badge variant="danger">{{ number_format($provider->errors) }}</x-ui.badge>
                    @else
                        0
                    @endif
                </td>
                <td>{{ $at($provider->lastReceivedAt) }}</td>
            </tr>
        @endforeach
    </x-admin.table>

    <h3 class="h6 mt-3">{{ __('Payment webhooks not processed') }}</h3>
    <x-admin.table :headers="array_merge([__('Received (IST)'), __('Event'), __('Event id'), __('Gateway order'), __('Failed attempts')], $canReplay ? [__('Action')] : [])"
                   :caption="__('Payment webhooks not processed')" :empty="$webhooks->unprocessed === []">
        <x-slot:emptyState>
            <x-ui.empty-state icon="fa-check" :title="__('Nothing waiting')" :message="__('Every stored payment webhook has been processed.')" />
        </x-slot:emptyState>
        @foreach ($webhooks->unprocessed as $event)
            <tr wire:key="unprocessed-{{ $event->gateway }}-{{ $event->eventId }}">
                <td>{{ $at($event->receivedAt) }}</td>
                <td><code>{{ $event->event }}</code></td>
                <td><code>{{ $event->eventId }}</code></td>
                <td>{{ $event->gatewayOrderId ?? '—' }}</td>
                <td>
                    @if ($event->failedAttempts > 0)
                        <x-ui.badge variant="danger">{{ number_format($event->failedAttempts) }}</x-ui.badge>
                        <span class="small text-muted-brand d-block">{{ $event->lastError }} · {{ $at($event->lastFailedAt) }}</span>
                    @else
                        0
                    @endif
                    @if ($event->replayedAt !== null)
                        <span class="small text-muted-brand d-block">{{ __('Replayed :time', ['time' => $at($event->replayedAt)]) }}</span>
                    @endif
                </td>
                @if ($canReplay)
                    <td>
                        <x-ui.button variant="outline" size="sm" type="button" icon="fa-repeat"
                                     x-on:click="$wire.set('replayGateway', {{ Js::from($event->gateway) }}, false); $wire.set('replayEventId', {{ Js::from($event->eventId) }}, false); $dispatch('open-modal', { name: 'webhook-replay' })"
                                     dusk="webhook-replay-open">{{ __('Replay') }}</x-ui.button>
                    </td>
                @endif
            </tr>
        @endforeach
    </x-admin.table>
    @if ($webhooks->unprocessedCount > count($webhooks->unprocessed))
        <p class="small text-muted-brand mt-2">{{ __('Showing the latest :shown of :count.', ['shown' => count($webhooks->unprocessed), 'count' => number_format($webhooks->unprocessedCount)]) }}</p>
    @endif
    @if ($canReplay)
        <x-admin.confirm-modal name="webhook-replay" :title="__('Replay payment webhook')" action="replay" :confirmLabel="__('Replay webhook')">
            <p>{{ __('Event :id is processed again from its stored copy. A payment is replayed only if Razorpay still lists it for the order with the same status; processing is idempotent, so a payment already applied is not applied twice.', ['id' => $replayEventId]) }}</p>
            @error('replayEventId') <p class="text-danger small mb-0">{{ $message }}</p> @enderror
        </x-admin.confirm-modal>
    @endif
    @endif

    {{-- Outbound delivery --}}
    <h2 class="admin-section-title">{{ __('Outbound email & SMS (last 24 hours)') }}</h2>
    @if ($delivery === null)
        <x-ui.alert type="warning">{{ __('Delivery figures couldn’t be read right now. The error has been reported; this refreshes every minute.') }}</x-ui.alert>
    @else
    <x-admin.table :headers="[__('Channel'), __('Sent'), __('Delivered'), __('Failed'), __('Bounced'), __('Bounce rate')]" :caption="__('Outbound delivery')">
        @foreach ($delivery as $channel)
            <tr wire:key="delivery-{{ $channel->channel->value }}">
                <th scope="row">{{ $channel->channel->label() }}</th>
                <td>{{ number_format($channel->sent) }}</td>
                <td>{{ number_format($channel->delivered) }}</td>
                <td>{{ number_format($channel->failed) }}</td>
                <td>{{ number_format($channel->bounced) }}</td>
                <td>{{ $pct($channel->bounceRatePct()) }}</td>
            </tr>
        @endforeach
    </x-admin.table>
    @endif

    {{-- Storage & database --}}
    <div class="row g-3">
        <div class="col-12 col-lg-5">
            <h2 class="admin-section-title">{{ __('Storage') }}</h2>
            <div class="ui-card">
                <dl class="small mb-0">
                    <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Free on the app server') }}</dt>
                        <dd class="mb-1 text-end">{{ $storage->freeBytes === null ? __('Unavailable') : Number::fileSize($storage->freeBytes, 1).' / '.Number::fileSize((int) $storage->totalBytes, 1) }}
                            @if ($storage->usedPct() !== null) <span class="text-muted-brand">({{ __(':pct used', ['pct' => $pct($storage->usedPct())]) }})</span> @endif
                        </dd></div>
                    @foreach ($storage->disks as $name => $driver)
                        <div class="d-flex justify-content-between gap-2" wire:key="disk-{{ $name }}"><dt class="fw-normal text-muted-brand">{{ __('Disk “:name”', ['name' => $name]) }}</dt><dd class="mb-1 text-end">{{ $driver }}</dd></div>
                    @endforeach
                </dl>
            </div>
        </div>
        <div class="col-12 col-lg-7">
            <h2 class="admin-section-title">{{ __('Database') }}</h2>
            <div class="ui-card">
                <dl class="small">
                    <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Size') }}</dt><dd class="mb-1 text-end">{{ $database->sizeBytes === null ? __('Unavailable') : Number::fileSize($database->sizeBytes, 1) }}</dd></div>
                    <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Open connections') }}</dt><dd class="mb-1 text-end">{{ $num($database->connections) }}</dd></div>
                    <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Up for') }}</dt><dd class="mb-1 text-end">{{ $seconds($database->uptimeSeconds) }}</dd></div>
                    <div class="d-flex justify-content-between gap-2"><dt class="fw-normal text-muted-brand">{{ __('Slow queries since start') }}</dt><dd class="mb-1 text-end">{{ $num($database->slowQueries) }}</dd></div>
                </dl>
                @if ($database->largestTables !== [])
                    <div class="admin-table-wrap">
                        <table class="admin-table">
                            <caption class="visually-hidden">{{ __('Largest tables') }}</caption>
                            <thead><tr><th scope="col">{{ __('Largest tables') }}</th><th scope="col">{{ __('Size') }}</th><th scope="col">{{ __('Rows (estimate)') }}</th></tr></thead>
                            <tbody>
                                @foreach ($database->largestTables as $table)
                                    <tr wire:key="table-{{ $table->name }}">
                                        <th scope="row"><code>{{ $table->name }}</code></th>
                                        <td>{{ Number::fileSize($table->bytes, 1) }}</td>
                                        <td>{{ number_format($table->rows) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
