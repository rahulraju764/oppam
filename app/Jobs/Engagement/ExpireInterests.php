<?php

declare(strict_types=1);

namespace App\Jobs\Engagement;

use App\Enums\InterestStatus;
use App\Models\Interest;
use App\Models\InterestEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * F02 "No response in 30 days → EXPIRED, quota not refunded": hourly, every PENDING interest past
 * its `expires_at` becomes EXPIRED, with a history row (actor = the system). In batches on the
 * (status, expires_at) index; each row is re-checked under a lock, so an interest answered a
 * moment earlier is left alone, and a second run finds nothing to do. Nobody is notified.
 */
final class ExpireInterests implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BATCH = 500;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        do {
            $ids = Interest::query()
                ->where('status', InterestStatus::Pending->value)
                ->where('expires_at', '<=', now())
                ->orderBy('expires_at')->limit(self::BATCH)->pluck('id');

            foreach ($ids as $id) {
                DB::transaction(function () use ($id): void {
                    $interest = Interest::query()->whereKey($id)->lockForUpdate()->first();

                    if ($interest === null || $interest->status !== InterestStatus::Pending || $interest->expires_at->isFuture()) {
                        return;
                    }

                    $interest->status = InterestStatus::Expired;
                    $interest->responded_at = now();
                    $interest->save();
                    InterestEvent::record($interest, InterestStatus::Expired, null);
                });
            }
        } while ($ids->count() === self::BATCH);
    }
}
