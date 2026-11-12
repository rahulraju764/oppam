<?php

declare(strict_types=1);

namespace App\Queries\Admin\Comms;

use App\Data\Comms\CampaignStatsData;
use App\Enums\CampaignRecipientStatus;
use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use App\Models\Campaign;
use Illuminate\Support\Facades\DB;

/**
 * A14 campaign numbers (P8.3b1), counted from campaign_recipients and the delivery log — two
 * grouped queries for a whole page of campaigns, never one per row.
 */
final class CampaignStats
{
    /**
     * @param  iterable<Campaign>  $campaigns
     * @return array<string, CampaignStatsData> keyed by campaign id
     */
    public function for(iterable $campaigns): array
    {
        $byId = [];
        foreach ($campaigns as $campaign) {
            $byId[$campaign->id] = $campaign;
        }
        if ($byId === []) {
            return [];
        }
        $ids = array_keys($byId);

        $recipients = DB::table('campaign_recipients')->whereIn('campaign_id', $ids)
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id, count(*) as audience')
            ->selectRaw('sum(status = ?) as pending', [CampaignRecipientStatus::Pending->value])
            ->selectRaw('sum(status = ?) as sent', [CampaignRecipientStatus::Sent->value])
            ->selectRaw('sum(status = ?) as skipped', [CampaignRecipientStatus::Skipped->value])
            ->selectRaw('sum(status = ?) as failed', [CampaignRecipientStatus::Failed->value])
            ->selectRaw('sum(clicked_at is not null) as clicked')
            ->selectRaw('sum(unsubscribed_at is not null) as unsubscribed')
            ->get()->keyBy('campaign_id');

        // A failed member counts once: a channel that throws (email) already left the recipient FAILED;
        // one that logs and swallows (SMS) leaves it SENT, so only those outbound failures are added.
        $outbound = DB::table('outbound_messages as o')->whereIn('o.campaign_id', $ids)
            ->leftJoin('campaign_recipients as r', fn ($j) => $j->on('r.campaign_id', '=', 'o.campaign_id')->on('r.user_id', '=', 'o.user_id'))
            ->groupBy('o.campaign_id')
            ->selectRaw('o.campaign_id as campaign_id')
            ->selectRaw('sum(o.status = ?) as delivered', [OutboundStatus::Delivered->value])
            ->selectRaw('sum(o.status = ?) as bounced', [OutboundStatus::Bounced->value])
            ->selectRaw('sum(o.status = ? and (r.status is null or r.status <> ?)) as failed', [OutboundStatus::Failed->value, CampaignRecipientStatus::Failed->value])
            ->selectRaw('sum(o.opened_at is not null) as opened')
            ->get()->keyBy('campaign_id');

        $stats = [];
        foreach ($byId as $id => $campaign) {
            $r = $recipients->get($id);
            $o = $outbound->get($id);
            $n = fn (?object $row, string $column): int => $row === null ? 0 : (int) $row->{$column};
            $sent = $n($r, 'sent');

            $stats[$id] = new CampaignStatsData(
                audience: $n($r, 'audience'),
                pending: $n($r, 'pending'),
                sent: $sent,
                skipped: $n($r, 'skipped'),
                failed: $n($r, 'failed') + $n($o, 'failed'),
                delivered: $campaign->channel === CommsChannel::InApp ? $sent : $n($o, 'delivered'),
                bounced: $n($o, 'bounced'),
                opened: $n($o, 'opened'),
                clicked: $n($r, 'clicked'),
                unsubscribed: $n($r, 'unsubscribed'),
            );
        }

        return $stats;
    }

    public function one(Campaign $campaign): CampaignStatsData
    {
        return $this->for([$campaign])[$campaign->id];
    }
}
