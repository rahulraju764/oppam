<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Models\BrokerKyc;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker (PRD §11A B.4 "Broker dashboard at a glance", P7.1 shell): the bureau's status (what to
 * do next before KYC) and the B.4 widgets each role may see — all with zero / empty data until
 * their modules arrive (referrals P7.2, managed profiles P7.3, inbox P7.4, imports P7.6, earnings
 * P7.8). Widgets are chosen with BureauGate, the same check the pages behind them use.
 */
final class Dashboard extends Component
{
    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::DashboardView);
    }

    public function render(BureauGate $gate): View
    {
        $user = $this->user();
        $staff = $gate->authorize($user, BureauPermission::DashboardView);
        $broker = $staff->broker ?? abort(404);
        $can = fn (BureauPermission $permission): bool => in_array($permission, BureauPermission::ceiling($staff->role), true);

        // B.4 "Visible to", by role ceiling: a widget shows even before KYC (with zeros), the page behind it is gated.
        $widgets = array_values(array_filter([
            $can(BureauPermission::ReferralsView) ? ['key' => 'referrals', 'title' => __('Referrals'), 'icon' => 'fa-share-alt',
                'stats' => [__('Total') => 0, __('This month') => 0, __('Converted') => 0, __('Conversion') => '0 %']] : null,
            ['key' => 'profiles', 'title' => __('Managed profiles'), 'icon' => 'fa-users',
                'stats' => [__('Total') => 0, __('Draft') => 0, __('In review') => 0, __('Active') => 0, __('Rejected') => 0, __('Claimed') => 0]],
            ['key' => 'moderation', 'title' => __('Moderation feed'), 'icon' => 'fa-check-square-o', 'empty' => __('Approvals and rejections of your profiles appear here as they happen.')],
            $can(BureauPermission::ProfilesImport) ? ['key' => 'imports', 'title' => __('Imports'), 'icon' => 'fa-upload', 'empty' => __('No import running.')] : null,
            $can(BureauPermission::ClientInboxRespond) ? ['key' => 'inbox', 'title' => __('Client inbox'), 'icon' => 'fa-inbox', 'stats' => [__('Pending interests') => 0]] : null,
            ['key' => 'followups', 'title' => __('Follow-ups due today'), 'icon' => 'fa-bell-o', 'empty' => __('No follow-ups due today.')],
            $can(BureauPermission::EarningsView) ? ['key' => 'commission', 'title' => __('Commission'), 'icon' => 'fa-inr',
                'stats' => [__('Earned') => '₹0', __('Pending') => '₹0', __('Paid') => '₹0']] : null,
            $can(BureauPermission::StaffManage) ? ['key' => 'staff', 'title' => __('Staff'), 'icon' => 'fa-id-badge',
                'stats' => [__('Seats used') => '1 / '.$broker->staff_seat_limit, __('Active today') => 1]] : null,
        ]));

        return view('livewire.broker.dashboard', [
            'broker' => $broker,
            'isOwner' => $staff->isOwner(),
            'latestKyc' => $staff->isOwner() ? BrokerKyc::query()->where('broker_id', $broker->id)->latest()->orderByDesc('id')->first() : null,
            'widgets' => $widgets,
        ])->layout('layouts::broker', ['seo' => SeoData::private('Bureau dashboard | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
