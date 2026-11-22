<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Actions\Admin\System\ReplayPaymentWebhook;
use App\Domain\System\ChannelTypes;
use App\Enums\SettingKey;
use App\Exceptions\Admin\WebhookReplayRefused;
use App\Models\AdminUser;
use App\Models\SystemAlert;
use App\Queries\System\DeliveryHealth;
use App\Queries\System\InfrastructureHealth;
use App\Queries\System\QueueHealth;
use App\Queries\System\RealtimeHealth;
use App\Queries\System\RequestHealth;
use App\Queries\System\SchedulerHealth;
use App\Queries\System\WebhookHealth;
use App\Services\System\Pulse\RequestOutcomes;
use App\Support\Facades\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * A12 system health panel (P9.2b1, `system.health.view`): queues (failed / retry / waiting),
 * broadcast queue lag, Reverb status, scheduler runs (missed ones marked), webhooks by provider
 * and the payment events not processed, outbound delivery and bounces, request latency / error
 * rate, storage and database. Every reader is cached and rescued to "unavailable", the page is
 * lazy (skeleton first) and re-reads itself every minute. Links to Horizon and Pulse for admins who
 * hold those permissions. The one write: replaying a stuck payment webhook (P9.2b2,
 * `system.webhooks.replay`, ReplayPaymentWebhook — reason required, audited).
 */
#[Lazy]
#[Layout('layouts::admin', ['title' => 'System health'])]
final class SystemHealth extends Component
{
    public const ALERTS_SHOWN = 20;

    public const RESOLVED_SHOWN_DAYS = 7;

    /** The webhook picked for replay (gateway + the gateway's own event id — never a ULID); checked again on replay. */
    public string $replayGateway = '';

    public string $replayEventId = '';

    public string $reason = '';

    /** @return array<string, string> */
    public function getListeners(): array
    {
        // An A12 alert opened / resolved (P9.2b2): re-read now rather than at the next minute's poll.
        // `admin.dashboard` admits `dashboard.view` holders only — subscribe only when it would be allowed.
        return $this->admin()->can('dashboard.view') ? ['echo-private:admin.dashboard,.system.alert' => '$refresh'] : [];
    }

    public function mount(): void
    {
        $this->authorize('system.health.view');
    }

    public function replay(ReplayPaymentWebhook $replay): void
    {
        $this->authorize('system.webhooks.replay');
        $this->resetErrorBag();
        $this->validate([
            'replayGateway' => ['required', 'string', 'regex:/^[a-z0-9_]{1,20}$/'],
            'replayEventId' => ['required', 'string', 'regex:/^[A-Za-z0-9_\-]{1,64}$/'],
        ]);

        try {
            $outcome = $replay->handle($this->admin(), $this->replayGateway, $this->replayEventId, $this->reason);
        } catch (WebhookReplayRefused $refused) {
            $this->dispatch('close-modal', name: 'webhook-replay');
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        app(WebhookHealth::class)->forget();
        $this->dispatch('close-modal', name: 'webhook-replay');
        $this->dispatch('toast', type: 'success', title: __('Webhook :id replayed: :outcome.', ['id' => $this->replayEventId, 'outcome' => str_replace('_', ' ', $outcome)]));
        $this->reset('reason', 'replayGateway', 'replayEventId');
    }

    public function placeholder(): View
    {
        return view('livewire.admin.system.system-health-placeholder');
    }

    public function render(
        QueueHealth $queues,
        SchedulerHealth $scheduler,
        WebhookHealth $webhooks,
        DeliveryHealth $delivery,
        RequestHealth $requests,
        RealtimeHealth $realtime,
        InfrastructureHealth $infrastructure,
    ): View {
        $this->authorize('system.health.view');   // every poll, not only mount: a revoked permission stops the next refresh
        $gate = Gate::forUser($this->admin());

        return view('livewire.admin.system.system-health', [
            'queues' => $queues->current(),
            // These readers query their own tables only; a failure (missing table after a partial
            // deploy, a lock timeout) is reported and that section says "couldn't be read" (null).
            'lag' => rescue(fn () => $realtime->broadcastLag(), null),
            'reverb' => $realtime->reverb(),
            'channelLabels' => ChannelTypes::labels(),
            'scheduler' => rescue(fn () => $scheduler->current(), null),
            'webhooks' => rescue(fn () => $webhooks->current(), null),
            'delivery' => rescue(fn () => $delivery->current(), null),
            'requests' => $requests->current(),
            'storage' => $infrastructure->storage(),
            'database' => $infrastructure->database(),
            'canReplay' => $gate->allows('system.webhooks.replay'),
            // A12 alerts (P9.2b2): open ones, then those resolved in the last week (null = couldn't be read).
            'alerts' => rescue(fn () => SystemAlert::query()
                ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays(self::RESOLVED_SHOWN_DAYS)))
                ->orderByRaw('resolved_at IS NULL DESC')->orderByDesc('opened_at')->limit(self::ALERTS_SHOWN)->get(), null),
            'horizonUrl' => $gate->allows('system.horizon') && Route::has('horizon.index') ? route('horizon.index') : null,
            'pulseUrl' => $gate->allows('system.pulse') && Route::has('pulse') ? route('pulse') : null,
            'checkedAt' => now(),
            'graceMinutes' => Settings::int(SettingKey::SystemMissedScheduleGraceMinutes),
            'p95Ceiling' => RequestOutcomes::BUCKETS[array_key_last(RequestOutcomes::BUCKETS)],
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = Auth::guard('admin')->user();

        return $admin;
    }
}
