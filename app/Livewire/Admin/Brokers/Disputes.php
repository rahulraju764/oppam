<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\Disputes\DecideConsentDispute;
use App\Actions\Admin\Brokers\Disputes\RecordConsentDispute;
use App\Enums\ConsentDisputeStatus;
use App\Enums\DisputeSource;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerConsentDispute;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 consent disputes (§11A B.12, R-A07-10; `brokers.view`): clients' "I never agreed to this
 * profile" complaints on managed profiles — every bureau's, or one bureau's (Disputes tab, `bureau`
 * #[Locked]) — with the linked A10 case and the outcome. `brokers.edit` records one (the profile is
 * suspended and an A10 case opened) and decides it (upheld / dismissed; upheld ones can pause the
 * bureau's create / import rights). Everything is re-authorized in the Actions.
 */
#[Layout('layouts::admin', ['title' => 'Consent disputes'])]
final class Disputes extends Component
{
    use WithPagination;

    #[Locked]
    public string $bureau = '';

    #[Url(except: 'OPEN')]
    public string $status = 'OPEN';

    #[Url(except: '')]
    public string $broker = '';

    public string $newBroker = '';

    public string $newProfile = '';

    public string $newSource = 'CALL';

    public string $note = '';

    public string $target = '';

    public string $outcome = '';

    public function mount(string $bureau = ''): void
    {
        $this->authorize('brokers.view');
        if ($bureau !== '') {
            $this->bureau = Broker::query()->where('code', strtoupper($bureau))->firstOrFail()->code;
            $this->status = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['status', 'broker'], true)) {
            $this->resetPage();
        }
    }

    public function record(RecordConsentDispute $action): void
    {
        $this->resetErrorBag();
        $code = $this->bureau !== '' ? $this->bureau : strtoupper(trim($this->newBroker));
        $broker = Broker::query()->where('code', $code)->first();
        if ($broker === null) {
            throw ValidationException::withMessages(['newBroker' => __('No bureau has this code.')]);
        }

        try {
            $dispute = $action->handle($this->admin(), $broker, $this->newProfile, DisputeSource::tryFrom($this->newSource) ?? DisputeSource::Call, $this->note);
        } catch (BrokerStateConflict $conflict) {
            throw ValidationException::withMessages(['newProfile' => $conflict->getMessage()]);
        }

        $this->reset('newBroker', 'newProfile', 'note');
        $this->dispatch('close-modal', name: 'dispute-record');
        $this->dispatch('toast', type: 'success', message: __('Dispute :code recorded. The profile is suspended and an A10 case is open.', ['code' => $dispute->code]));
    }

    public function decide(string $code): void
    {
        $this->resetErrorBag();
        $this->reset('outcome');
        $this->target = $code;
        $this->dispatch('open-modal', name: 'dispute-decide');
    }

    public function uphold(DecideConsentDispute $action): void
    {
        $this->settle($action, true);
    }

    public function dismiss(DecideConsentDispute $action): void
    {
        $this->settle($action, false);
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }

    public function render(): View
    {
        $this->authorize('brokers.view');

        return view('livewire.admin.brokers.disputes', [
            'disputes' => $this->disputes(),
            'statuses' => collect(ConsentDisputeStatus::cases())->mapWithKeys(fn (ConsentDisputeStatus $s): array => [$s->value => $s->label()])->all(),
            'sources' => DisputeSource::options(),
            'embedded' => $this->bureau !== '',
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    private function settle(DecideConsentDispute $action, bool $upheld): void
    {
        $this->resetErrorBag();

        // Embedded in a bureau's page: only that bureau's disputes, whatever code the browser sends.
        if ($this->bureau !== '' && ! BrokerConsentDispute::query()->where('code', strtoupper(trim($this->target)))
            ->whereHas('broker', fn (Builder $b) => $b->where('code', $this->bureau))->exists()) {
            $this->dispatch('toast', type: 'error', message: __('This dispute no longer exists.'));

            return;
        }

        try {
            $result = $action->handle($this->admin(), $this->target, $upheld, $this->outcome);
        } catch (BrokerStateConflict $conflict) {
            $this->dispatch('toast', type: 'error', message: $conflict->getMessage());

            return;
        } catch (ModelNotFoundException) {
            $this->dispatch('toast', type: 'error', message: __('This dispute no longer exists.'));

            return;
        }

        $this->reset('outcome', 'target');
        $this->dispatch('close-modal', name: 'dispute-decide');
        $this->dispatch('toast', type: 'success', message: match (true) {
            $result['creation_paused'] => __('Upheld. The bureau has reached the dispute limit: adding and importing profiles is now paused.'),
            $upheld => __('Dispute upheld. The profile stays suspended.'),
            default => __('Dispute dismissed. The profile is back to its earlier status.'),
        });
    }

    /** @return LengthAwarePaginator<int, BrokerConsentDispute> */
    private function disputes(): LengthAwarePaginator
    {
        $code = $this->bureau !== '' ? $this->bureau : strtoupper(trim($this->broker));

        return BrokerConsentDispute::query()
            ->with(['broker:id,code,name,bureau_name', 'profile:id,code,status', 'abuseCase:id,code', 'recordedBy:id,name', 'decidedBy:id,name'])
            ->when($code !== '', fn (Builder $q) => $q->whereHas('broker', fn (Builder $b) => $b->where('code', $code)))
            ->when(ConsentDisputeStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->latest()->orderByDesc('id')
            ->paginate(25);
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
