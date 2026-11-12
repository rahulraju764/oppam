<?php

declare(strict_types=1);

namespace App\Jobs\Comms;

use App\Domain\Comms\CampaignStarter;
use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/**
 * Every minute (P8.3b1): starts SCHEDULED campaigns whose time has come, and restarts a SENDING
 * campaign whose next batch is STALL_MINUTES overdue (a worker died, a deploy flushed the queue),
 * so a send never hangs silently. A restart replaces the campaign's batch chain
 * (SendCampaignBatch::schedule issues a new token): if the "lost" batch turns up later, it exits —
 * never two chains, so the throttle holds. An SMS campaign held by quiet hours is due at the end of
 * the window, so it isn't overdue before then.
 */
final class StartDueCampaigns implements ShouldQueue
{
    use Dispatchable, Queueable;

    public const STALL_MINUTES = 10;

    public function handle(CampaignStarter $starter): void
    {
        Campaign::query()
            ->where('status', CampaignStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->pluck('id')
            ->each(fn (string $id) => DB::transaction(function () use ($id, $starter): void {
                $campaign = Campaign::query()->whereKey($id)->lockForUpdate()->first();
                if ($campaign?->status === CampaignStatus::Scheduled) {
                    $starter->begin($campaign);
                }
            }));

        $cutoff = now()->subMinutes(self::STALL_MINUTES);
        Campaign::query()
            ->where('status', CampaignStatus::Sending->value)
            ->where(fn ($q) => $q->whereNull('next_batch_at')->orWhere('next_batch_at', '<', $cutoff))
            ->pluck('id')
            ->each(fn (string $id) => DB::transaction(function () use ($id, $cutoff): void {
                $campaign = Campaign::query()->whereKey($id)->lockForUpdate()->first();
                if ($campaign?->status === CampaignStatus::Sending
                    && ($campaign->next_batch_at === null || $campaign->next_batch_at->lt($cutoff))) {
                    SendCampaignBatch::schedule($campaign);   // next_batch_at = now: one restart per STALL_MINUTES
                }
            }));
    }
}
