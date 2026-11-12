<?php

declare(strict_types=1);

namespace App\Http\Controllers\Member;

use App\Actions\Comms\UnsubscribeFromCampaignEmails;
use App\Domain\Comms\CampaignLinks;
use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * The two links in an A14 campaign message (P8.3b1), behind the `signed` middleware: a wrong or
 * edited link is a 403 before this runs; a recipient that isn't in that campaign is a 404.
 *  - click: counts the first click, then goes to the campaign's button link;
 *  - unsubscribe: GET shows a confirmation (mail scanners open links on their own), POST turns the
 *    emails off — the POST is also the RFC 8058 one-click target, so it is CSRF-exempt.
 */
final class CampaignLinkController extends Controller
{
    public function click(string $campaign, int $recipient): RedirectResponse
    {
        [$row, $member] = $this->find($campaign, $recipient);
        if ($row->action_url === null) {
            abort(404);
        }

        CampaignRecipient::query()->whereKey($member->id)->whereNull('clicked_at')->update(['clicked_at' => now()]);

        return redirect()->away(CampaignLinks::destination($row));
    }

    public function showUnsubscribe(string $campaign, int $recipient): View
    {
        $this->find($campaign, $recipient);

        return view('pages.campaign-unsubscribe', ['done' => false]);
    }

    public function unsubscribe(string $campaign, int $recipient, UnsubscribeFromCampaignEmails $unsubscribe): View
    {
        [, $member] = $this->find($campaign, $recipient);
        $unsubscribe->handle($member);

        return view('pages.campaign-unsubscribe', ['done' => true]);
    }

    /** @return array{0: Campaign, 1: CampaignRecipient} */
    private function find(string $code, int $recipientId): array
    {
        $campaign = Campaign::query()->where('code', $code)->firstOrFail();
        $recipient = CampaignRecipient::query()->whereKey($recipientId)->where('campaign_id', $campaign->id)->firstOrFail();

        return [$campaign, $recipient];
    }
}
