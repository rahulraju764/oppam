<?php

declare(strict_types=1);

namespace App\Jobs\Comms;

use App\Models\OutboundMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Daily (routes/console.php): the A14 delivery log keeps `oppam.comms.outbound_retention_days`
 * (180, like in-app notifications R-M08-4); webhook replay tokens a day. Batched, idempotent.
 */
final class PruneOutboundMessages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $before = now()->subDays((int) config('oppam.comms.outbound_retention_days'));

        // A row resent from a deleted one keeps living: resent_from_id is null-on-delete.
        do {
            $deleted = OutboundMessage::query()->where('created_at', '<', $before)->limit(1000)->delete();
        } while ($deleted === 1000);

        DB::table('webhook_replay_tokens')->where('created_at', '<', now()->subDay())->delete();
    }
}
