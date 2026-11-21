<?php

declare(strict_types=1);

namespace App\Livewire\Admin\System;

use App\Domain\System\ChannelTypes;
use App\Enums\SettingKey;
use App\Models\AdminUser;
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
 * rate, storage and database. Read-only. Every reader is cached and rescued to "unavailable", the
 * page is lazy (skeleton first) and re-reads itself every minute. Links to Horizon and Pulse for
 * admins who hold those permissions.
 */
#[Lazy]
#[Layout('layouts::admin', ['title' => 'System health'])]
final class SystemHealth extends Component
{
    public function mount(): void
    {
        $this->authorize('system.health.view');
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
        $gate = Gate::forUser($this->admin());

        return view('livewire.admin.system.system-health', [
            'queues' => $queues->current(),
            'lag' => $realtime->broadcastLag(),
            'reverb' => $realtime->reverb(),
            'channelLabels' => ChannelTypes::labels(),
            'scheduler' => $scheduler->current(),
            'webhooks' => $webhooks->current(),
            'delivery' => $delivery->current(),
            'requests' => $requests->current(),
            'storage' => $infrastructure->storage(),
            'database' => $infrastructure->database(),
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
