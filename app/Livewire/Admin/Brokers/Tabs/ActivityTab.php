<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers\Tabs;

use App\Livewire\Admin\Brokers\Concerns\IsBureauTab;
use App\Models\BrokerStaff;
use App\Queries\Broker\BureauActivity;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bureau "Activity" (§11A B.12 audit timeline): what the bureau's logins did (BureauActivity —
 * the Owner's own log) and, unfiltered, what A07 did to the bureau. Filters by staff code, action
 * group and IST dates (allow-listed in the query). `brokers.view`.
 */
final class ActivityTab extends Component
{
    use IsBureauTab, WithPagination;

    public string $staff = '';

    public string $group = '';

    public string $from = '';

    public string $to = '';

    public function mount(string $code): void
    {
        $this->openTab($code);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(BureauActivity $activity): View
    {
        $broker = $this->broker();
        $staff = BrokerStaff::query()->where('broker_id', $broker->id)->orderBy('code')->pluck('code', 'code')->all();

        return view('livewire.admin.brokers.tabs.activity-tab', [
            'rows' => $activity->adminPage($broker, [
                'staff' => isset($staff[$this->staff]) ? $this->staff : '',
                'group' => array_key_exists($this->group, BureauActivity::GROUPS) ? $this->group : '',
                'from' => $this->from, 'to' => $this->to,
            ]),
            'staffOptions' => $staff,
            'codeByLogin' => BrokerStaff::query()->where('broker_id', $broker->id)->pluck('code', 'user_id')->all(),
            'groups' => ['profiles' => __('Profiles'), 'inbox' => __('Client inbox'), 'exports' => __('Exports'), 'notes' => __('Follow-ups'), 'staff' => __('Staff')],
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
