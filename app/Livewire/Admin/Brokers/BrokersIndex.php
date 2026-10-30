<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\CreateBureau;
use App\Enums\BrokerKycStatus;
use App\Enums\BrokerStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Livewire\Forms\BureauForm;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Queries\Admin\BrokerDirectoryQuery;
use App\Services\Masters\Masters;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 broker directory (§11A B.12, `brokers.view`): every bureau with referrals, managed profiles,
 * conversion rate, commission, KYC, seats, imports paused / payouts held and status; search and
 * filters in the URL. "Create broker" (`brokers.edit`, CreateBureau) opens a bureau record whose
 * Owner then applies with the same mobile (decisions 2026-10-29).
 *
 * @property-read LengthAwarePaginator<int, Broker> $brokers
 */
#[Layout('layouts::admin', ['title' => 'Brokers'])]
final class BrokersIndex extends Component
{
    use WithPagination;

    private const FILTERS = ['q', 'status', 'kyc', 'district', 'flag', 'sort'];

    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $kyc = '';

    #[Url(except: '')]
    public string $district = '';

    #[Url(except: '')]
    public string $flag = '';

    #[Url(except: 'newest')]
    public string $sort = 'newest';

    public BureauForm $form;

    public function mount(): void
    {
        $this->authorize('brokers.view');
    }

    public function updated(string $property): void
    {
        if (in_array($property, self::FILTERS, true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(...self::FILTERS);
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Broker> */
    #[Computed]
    public function brokers(): LengthAwarePaginator
    {
        return app(BrokerDirectoryQuery::class)->page([
            'q' => $this->q, 'status' => $this->status, 'kyc' => $this->kyc, 'district' => $this->district,
            'flag' => $this->flag, 'sort' => in_array($this->sort, BrokerDirectoryQuery::SORTS, true) ? $this->sort : 'newest',
        ]);
    }

    public function create(CreateBureau $create): mixed
    {
        $this->resetErrorBag();
        $data = $this->form->toData();

        try {
            $broker = $create->handle($this->admin(), $data);
        } catch (BrokerStateConflict $conflict) {
            $this->addError('form.mobile', $conflict->getMessage());

            return null;
        }

        $this->form->reset();

        // The detail page shows the "waiting for the owner" notice with the application link.
        return $this->redirectRoute('admin.brokers.show', ['broker' => $broker->code], navigate: true);
    }

    public function render(Masters $masters): View
    {
        return view('livewire.admin.brokers.brokers-index', [
            'statuses' => BrokerStatus::options(),
            'kycStatuses' => BrokerKycStatus::options(),
            'districts' => Masters::forSelect($masters->allDistricts()),
        ]);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
