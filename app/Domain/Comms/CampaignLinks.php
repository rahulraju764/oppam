<?php

declare(strict_types=1);

namespace App\Domain\Comms;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Support\Facades\URL;

/**
 * The two links a campaign message carries for one recipient (P8.3b1): the tracked button and the
 * one-click unsubscribe. Both are signed URLs built when the message is built — nothing secret is
 * stored or queued (decisions 2026-11-08 P8.3b row). The signature covers the campaign code and the
 * recipient row, so a link can't be pointed at another member; the URL carries no destination — a
 * click goes to the link saved on the campaign (destination()). No expiry: an unsubscribe must keep working.
 */
final class CampaignLinks
{
    public function click(Campaign $campaign, CampaignRecipient $recipient): ?string
    {
        if ($campaign->action_url === null) {
            return null;
        }

        return URL::signedRoute('campaigns.click', ['campaign' => $campaign->code, 'recipient' => $recipient->id]);
    }

    public function unsubscribe(Campaign $campaign, CampaignRecipient $recipient): string
    {
        return URL::signedRoute('campaigns.unsubscribe', ['campaign' => $campaign->code, 'recipient' => $recipient->id]);
    }

    /** Where a click goes: a path on this site, or the https address the admin set (SaveCampaign checked it). */
    public static function destination(Campaign $campaign): string
    {
        $target = (string) $campaign->action_url;

        return str_starts_with($target, '/') ? url($target) : $target;
    }
}
