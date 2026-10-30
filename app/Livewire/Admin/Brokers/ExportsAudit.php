<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Enums\BrokerExportType;
use App\Models\Broker;
use App\Queries\Admin\BrokerExportAudit;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 export audit (§11A B.12, R-A07-7, `brokers.exports.view`): every export attempt by every
 * bureau — or, inside a bureau's page (Exports tab), that bureau's only (`bureau` is #[Locked]) —
 * with filters in the URL and unusual rows highlighted (BrokerExportAudit). Read-only: there is no
 * edit or delete anywhere, and the model refuses both.
 */
#[Layout('layouts::admin', ['title' => 'Export audit'])]
final class ExportsAudit extends Component
{
    use WithPagination;

    /** A bureau code when embedded in its page; '' for the all-bureaus screen. */
    #[Locked]
    public string $bureau = '';

    #[Url(except: '')]
    public string $broker = '';

    #[Url(except: '')]
    public string $staff = '';

    #[Url(except: '')]
    public string $profile = '';

    #[Url(except: '')]
    public string $type = '';

    #[Url(except: '')]
    public string $allowed = '';

    #[Url(except: '')]
    public string $from = '';

    #[Url(except: '')]
    public string $to = '';

    public function mount(string $bureau = ''): void
    {
        $this->authorize('brokers.exports.view');
        if ($bureau !== '') {
            $this->bureau = Broker::query()->where('code', strtoupper($bureau))->firstOrFail()->code;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['broker', 'staff', 'profile', 'type', 'allowed', 'from', 'to'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset('broker', 'staff', 'profile', 'type', 'allowed', 'from', 'to');
        $this->resetPage();
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }

    public function render(BrokerExportAudit $audit): View
    {
        $this->authorize('brokers.exports.view');
        $result = $audit->page([
            'broker' => $this->bureau !== '' ? $this->bureau : $this->broker,
            'staff' => $this->staff, 'profile' => $this->profile, 'type' => $this->type,
            'allowed' => $this->allowed, 'from' => $this->from, 'to' => $this->to,
        ]);

        return view('livewire.admin.brokers.exports-audit', [
            'rows' => $result['rows'],
            'unknown' => $result['unknown'],
            'types' => BrokerExportType::options(),
            'embedded' => $this->bureau !== '',
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }
}
