<?php

declare(strict_types=1);

namespace App\Services\Health;

use App\Contracts\IntegrationProbe;
use App\Data\Settings\IntegrationStatusData;
use App\Enums\Flag;
use App\Models\AdminUser;
use App\Support\Facades\Feature;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Support\Facades\Broadcast;
use Throwable;

/** Real-time (PRD §9): broadcast driver + the live-updates flag; the check asks the Reverb / Pusher server for its channels. */
final class BroadcastProbe implements IntegrationProbe
{
    public function key(): string
    {
        return 'broadcast';
    }

    public function label(): string
    {
        return __('Live updates (WebSockets)');
    }

    public function facts(): array
    {
        return [
            __('Driver') => (string) config('broadcasting.default'),
            __('Live updates flag') => Feature::active(Flag::RealtimeEnabled) ? __('On') : __('Off'),
            __('Polling fallback flag') => Feature::active(Flag::RealtimePollingFallback) ? __('On') : __('Off'),
        ];
    }

    public function check(AdminUser $admin): IntegrationStatusData
    {
        $driver = (string) config('broadcasting.default');
        if (! in_array($driver, ['reverb', 'pusher'], true)) {
            return IntegrationStatusData::warning(__('Broadcast driver ":driver" sends nothing live.', ['driver' => $driver]));
        }

        try {
            $broadcaster = Broadcast::connection($driver);
            if (! $broadcaster instanceof PusherBroadcaster) {
                return IntegrationStatusData::warning(__('Unexpected broadcaster for ":driver".', ['driver' => $driver]));
            }
            $broadcaster->getPusher()->getChannels();
        } catch (Throwable $e) {
            report($e);

            return IntegrationStatusData::error(__('The :driver server could not be reached — is it running?', ['driver' => $driver]));
        }

        return IntegrationStatusData::ok(__('The :driver server answered.', ['driver' => $driver]));
    }
}
