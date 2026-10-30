<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Brokers;

use App\Actions\Admin\Brokers\Imports\CancelImportBatchAsAdmin;
use App\Actions\Admin\Brokers\SetBrokerImportsPause;
use App\Enums\ImportBatchStatus;
use App\Exceptions\Admin\BrokerStateConflict;
use App\Exceptions\Broker\ImportRefused;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * A07 bulk-import monitor (§11A B.12, R-A07-9, R-M13-32; `brokers.imports.view`): every batch of
 * every bureau — or one bureau's (Imports tab, `bureau` #[Locked]) — with approval / rejection
 * rates, the paused bureaus first (the auto-pause review queue), and for `brokers.imports.manage`:
 * pause / resume a bureau's imports, cancel a batch, download its source files (audited, until the
 * purge). Daily caps are changed on the bureau's Overview (terms). Each action authorizes again in
 * its Action and asks for a reason.
 */
#[Layout('layouts::admin', ['title' => 'Bulk imports'])]
final class ImportsMonitor extends Component
{
    use WithPagination;

    #[Locked]
    public string $bureau = '';

    #[Url(except: '')]
    public string $broker = '';

    #[Url(except: '')]
    public string $status = '';

    /** The bureau / batch a dialog acts on (any bureau — the admin's permission covers all of them). */
    public string $targetBroker = '';

    public string $targetBatch = '';

    public string $reason = '';

    public function mount(string $bureau = ''): void
    {
        $this->authorize('brokers.imports.view');
        if ($bureau !== '') {
            $this->bureau = Broker::query()->where('code', strtoupper($bureau))->firstOrFail()->code;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['broker', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function confirm(string $modal, string $broker, string $batch = ''): void
    {
        $this->resetErrorBag();
        $this->reset('reason');
        $this->targetBroker = $this->bureau !== '' ? $this->bureau : strtoupper($broker);
        $this->targetBatch = $batch;
        $this->dispatch('open-modal', name: $modal);
    }

    public function pause(SetBrokerImportsPause $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->target(), true, $this->reason), 'imports-pause',
            __('Imports paused for :code. Single profiles can still be added.', ['code' => $this->targetBroker]));
    }

    public function resume(SetBrokerImportsPause $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->target(), false, $this->reason), 'imports-resume',
            __('Imports resumed for :code.', ['code' => $this->targetBroker]));
    }

    public function cancelBatch(CancelImportBatchAsAdmin $action): void
    {
        $this->run(fn () => $action->handle($this->admin(), $this->target()->id, $this->targetBatch, $this->reason), 'imports-cancel',
            __('Batch :code cancelled.', ['code' => $this->targetBatch]));
    }

    public function placeholder(): View
    {
        return view('livewire.admin.members.tabs.placeholder');
    }

    public function render(): View
    {
        $this->authorize('brokers.imports.view');

        return view('livewire.admin.brokers.imports-monitor', [
            'batches' => $this->batches(),
            'paused' => $this->pausedBureaus(),
            'statuses' => collect(ImportBatchStatus::cases())->mapWithKeys(fn (ImportBatchStatus $s): array => [$s->value => $s->label()])->all(),
            'embedded' => $this->bureau !== '',
            'timezone' => (string) config('oppam.display_timezone'),
        ]);
    }

    /** @return LengthAwarePaginator<int, BrokerImportBatch> */
    private function batches(): LengthAwarePaginator
    {
        $code = $this->bureau !== '' ? $this->bureau : strtoupper(trim($this->broker));

        return BrokerImportBatch::query()
            ->with(['broker:id,code,bureau_name,name,imports_paused_at', 'uploaderStaff:id,user_id,code,role'])
            ->when($code !== '', fn (Builder $q) => $q->whereHas('broker', fn (Builder $b) => $b->where('code', $code)))
            ->when(ImportBatchStatus::tryFrom($this->status) !== null, fn (Builder $q) => $q->where('status', $this->status))
            ->latest()->orderByDesc('id')
            ->paginate(25);
    }

    /** @return Collection<int, Broker> */
    private function pausedBureaus(): Collection
    {
        return Broker::query()->whereNotNull('imports_paused_at')
            ->when($this->bureau !== '', fn (Builder $q) => $q->where('code', $this->bureau))
            ->orderBy('imports_paused_at')->limit(50)
            ->get(['id', 'code', 'name', 'bureau_name', 'imports_paused_at', 'imports_paused_reason', 'imports_paused_batch_id']);
    }

    private function run(Closure $action, string $modal, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (BrokerStateConflict|ImportRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());

            return;
        }

        $this->reset('reason', 'targetBatch');
        $this->dispatch('close-modal', name: $modal);
        $this->dispatch('toast', type: 'success', message: $done);
    }

    private function target(): Broker
    {
        return Broker::query()->where('code', $this->bureau !== '' ? $this->bureau : $this->targetBroker)->firstOrFail();
    }

    private function admin(): AdminUser
    {
        /** @var AdminUser */
        return auth('admin')->user();
    }
}
