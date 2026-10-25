<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\ModerationStatus;
use App\Models\BrokerKyc;
use App\Models\User;
use App\Queries\Broker\BureauReferrals;
use App\Queries\Broker\ManagedProfileList;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker (PRD §11A B.4 "Broker dashboard at a glance", P7.1 shell): the bureau's status (what to
 * do next before KYC) and the B.4 widgets each role may see — referrals live from P7.2, the rest
 * live from P7.3 for managed profiles, the moderation feed (refreshed on `profile.moderated`) and
 * follow-ups, the rest with zero / empty data until their modules arrive (inbox P7.4, imports P7.6,
 * earnings P7.8). Widgets are chosen with BureauGate, the same check the pages behind them use.
 */
final class Dashboard extends Component
{
    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::DashboardView);
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff !== null ? [
            'echo-private:broker.'.$staff->broker_id.',.profile.moderated' => '$refresh',
            'echo-private:broker.'.$staff->broker_id.',.client.interest' => '$refresh',   // Client inbox badge (B.15)
            'echo-private:broker.'.$staff->broker_id.',.profile.claimed' => '$refresh',
        ] : [];
    }

    public function render(BureauGate $gate, BureauReferrals $referrals, ManagedProfileList $managed): View
    {
        $user = $this->user();
        $staff = $gate->authorize($user, BureauPermission::DashboardView);
        $broker = $staff->broker ?? abort(404);
        $counts = $managed->counts($staff);
        $can = fn (BureauPermission $permission): bool => in_array($permission, BureauPermission::ceiling($staff->role), true);

        // B.4 "Visible to", by role ceiling: a widget shows even before KYC (with zeros), the page behind it is gated.
        $widgets = array_values(array_filter([
            $can(BureauPermission::ReferralsView) ? ['key' => 'referrals', 'title' => __('Referrals'), 'icon' => 'fa-share-alt',
                'stats' => self::referralStats($referrals->stats($broker->id))] : null,
            ['key' => 'profiles', 'title' => __('Managed profiles'), 'icon' => 'fa-users', 'stats' => [
                __('Total') => $counts['total'], __('Draft') => $counts['draft'], __('In review') => $counts['review'],
                __('Active') => $counts['active'], __('Rejected') => $counts['rejected'], __('Claimed') => $counts['claimed'],
            ]],
            ['key' => 'moderation', 'title' => __('Moderation feed'), 'icon' => 'fa-check-square-o', 'items' => self::feed($managed->latestDecisions($staff)),
                'empty' => __('Approvals and rejections of your profiles appear here as they happen.')],
            $can(BureauPermission::ProfilesImport) ? ['key' => 'imports', 'title' => __('Imports'), 'icon' => 'fa-upload', 'empty' => __('No import running.')] : null,
            $can(BureauPermission::ClientInboxRespond) ? ['key' => 'inbox', 'title' => __('Client inbox'), 'icon' => 'fa-inbox',
                'stats' => [__('Pending interests') => $gate->allows($user, BureauPermission::ClientInboxRespond) ? $managed->pendingInterests($staff) : 0]] : null,
            ['key' => 'followups', 'title' => __('Follow-ups due today'), 'icon' => 'fa-bell-o',
                'items' => $can(BureauPermission::ClientNotesWrite) && $gate->allows($user, BureauPermission::ClientNotesWrite) ? self::followUps($managed->dueFollowUps($staff)) : [],
                'empty' => __('No follow-ups due today.')],
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

    /**
     * @param  array{total: int, this_month: int, converted: int}  $stats
     * @return array<string, int|string>
     */
    private static function referralStats(array $stats): array
    {
        return [
            __('Total') => $stats['total'],
            __('This month') => $stats['this_month'],
            __('Converted') => $stats['converted'],
            __('Conversion') => ($stats['total'] > 0 ? (int) round($stats['converted'] * 100 / $stats['total']) : 0).' %',
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\ModerationItem>  $items
     * @return list<array{label: string, url: string, badge: string, variant: string}>
     */
    private static function feed(\Illuminate\Support\Collection $items): array
    {
        return $items->filter(fn ($item): bool => $item->profile !== null)->map(fn ($item): array => [
            'label' => $item->profile->code.' · '.$item->decided_at?->timezone(config('oppam.display_timezone'))->format('j M'),
            'url' => route('broker.profiles.show', ['code' => $item->profile->code]),
            'badge' => $item->status === ModerationStatus::Approved ? __('Approved') : __('Needs changes'),
            'variant' => $item->status === ModerationStatus::Approved ? 'success' : 'warning',
        ])->values()->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\BrokerClientNote>  $notes
     * @return list<array{label: string, url: string, badge: string, variant: string}>
     */
    private static function followUps(\Illuminate\Support\Collection $notes): array
    {
        $today = now(config('oppam.display_timezone'))->format('Y-m-d');

        return $notes->filter(fn ($note): bool => $note->profile !== null)->map(fn ($note): array => [
            'label' => $note->profile->code.' · '.$note->type->label(),
            'url' => route('broker.profiles.notes', ['code' => $note->profile->code]),
            'badge' => $note->follow_up_on?->format('Y-m-d') === $today ? __('Today') : __('Overdue'),
            'variant' => $note->follow_up_on?->format('Y-m-d') === $today ? 'info' : 'warning',
        ])->values()->all();
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
