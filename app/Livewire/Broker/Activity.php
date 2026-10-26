<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Models\Broker;
use App\Models\BrokerStaff;
use App\Models\User;
use App\Queries\Broker\BureauActivity;
use App\Queries\Broker\StaffDirectory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/activity (§11A B.8 activity log, B.11 — Owner, Manager via `activity.view`): who in the
 * bureau did what and when, newest first, filtered by staff member, date range (IST) and kind of
 * action (`#[Url]`, allow-listed in BureauActivity). Only this bureau's logins' `broker.*` audit rows.
 */
final class Activity extends Component
{
    use WithPagination;

    #[Url]
    public string $staff = '';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    #[Url]
    public string $group = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['staff', 'from', 'to', 'group'], true)) {
            $this->group = array_key_exists($this->group, BureauActivity::GROUPS) ? $this->group : '';
            $this->resetPage();
        }
    }

    public function render(BureauGate $gate, BureauActivity $activity, StaffDirectory $directory): View
    {
        $viewer = $gate->authorize($this->user(), BureauPermission::ActivityView);
        /** @var Broker $broker */
        $broker = $viewer->broker;
        $members = $directory->staff($broker);

        return view('livewire.broker.activity', [
            'rows' => $activity->page($viewer, ['staff' => $this->staff, 'from' => $this->from, 'to' => $this->to, 'group' => $this->group]),
            'names' => $members->mapWithKeys(fn (BrokerStaff $s): array => [$s->user_id => $s->displayName()])->all(),
            'members' => $members->mapWithKeys(fn (BrokerStaff $s): array => [$s->code => $s->displayName()])->all(),
            'groups' => [
                'profiles' => __('Profiles'), 'inbox' => __('Client inbox'), 'exports' => __('Exports'),
                'notes' => __('Follow-ups'), 'staff' => __('Staff'),
            ],
        ])->layout('layouts::broker', ['seo' => SeoData::private('Bureau activity | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
