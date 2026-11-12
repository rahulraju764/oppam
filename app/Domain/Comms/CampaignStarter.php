<?php

declare(strict_types=1);

namespace App\Domain\Comms;

use App\Enums\CampaignStatus;
use App\Jobs\Comms\SendCampaignBatch;
use App\Models\Campaign;

/**
 * Moves a campaign whose audience is frozen (and approved, when it needed it) on to sending
 * (P8.3b1): a start time still in the future → SCHEDULED (StartDueCampaigns starts it); otherwise
 * SENDING at once, with the first batch of its one chain queued after the caller's transaction
 * commits (SendCampaignBatch::schedule). Called inside SubmitCampaign / ApproveCampaign's locked
 * transaction and by StartDueCampaigns.
 */
final class CampaignStarter
{
    public function begin(Campaign $campaign): void
    {
        if ($campaign->scheduled_at !== null && $campaign->scheduled_at->isFuture()) {
            $campaign->forceFill(['status' => CampaignStatus::Scheduled])->save();

            return;
        }

        $campaign->forceFill(['status' => CampaignStatus::Sending, 'started_at' => now()])->save();
        SendCampaignBatch::schedule($campaign);
    }
}
