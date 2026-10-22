<?php

declare(strict_types=1);

namespace App\Livewire\Member\Billing;

use App\Data\Content\SeoData;
use App\Models\Order;
use App\Models\User;
use App\Queries\Billing\MembershipSummary;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /billing (PRD M10 "Billing history page: orders, invoices (download), current plan, usage meters"):
 * the plan in force and any plan already bought that starts later, the §7.3 usage meters with
 * their reset dates, the plan's on / off features, and the member's own orders — 10 a page — with
 * invoice downloads. Read-only, so an admin support session sees it too. Orders that never reached
 * the gateway (it was down) are left out. `.entitlements.changed` re-renders it live.
 */
final class History extends Component
{
    use WithPagination;

    public const PER_PAGE = 10;

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:App.Models.User.'.$this->member()->id.',.entitlements.changed' => '$refresh',
            'realtime-reconnected' => '$refresh',
        ];
    }

    public function render(MembershipSummary $summary): View
    {
        $profile = $this->member()->profile ?? abort(404);

        $orders = Order::query()
            ->where('profile_id', $profile->id)
            ->whereNotNull('gateway_order_id')
            ->with(['plan:id,name', 'invoice:id,order_id,number'])
            ->latest()
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.member.billing.history', [
            'summary' => $summary->for($profile),
            'orders' => $orders,
            'displayZone' => (string) config('oppam.display_timezone'),
        ])->layout('layouts::member', [
            'seo' => SeoData::private('Membership & billing | Oppam Matrimony'),
        ]);
    }

    private function member(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
