<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Import;

use App\Actions\Broker\Import\SaveColumnMapping;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/import/{batch} (§11A B.9 steps 2–3, B.11 `profiles.import`): the batch's live validation
 * (progress bar driven by ImportProgress on `broker.{broker}`; polling only behind the
 * `realtime.polling_fallback` flag), the column-matching form while MAPPING, and once READY the
 * row preview — VALID / WARNING / ERROR badges, a `#[Url]` filter, each row's messages — plus the
 * errors.xlsx download. The code is locked and looked up inside the caller's bureau (else 404).
 */
final class BatchReview extends Component
{
    use WithPagination;

    public const FILTERS = ['all', 'valid', 'warning', 'error'];

    private const PER_PAGE = 25;

    #[Locked]
    public string $code = '';

    #[Url(as: 'rows')]
    public string $filter = 'all';

    /** @var array<string, string> field => sheet header (the mapping form) */
    public array $mapping = [];

    public function mount(string $batch): void
    {
        $this->code = $batch;
        $model = $this->batch();
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';
        $this->mapping = array_map('strval', array_filter($model->column_map ?? [], 'is_string'));
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $staff = app(BureauGate::class)->staff($this->user());

        return $staff !== null ? [
            'echo-private:broker.'.$staff->broker_id.',.import.progress' => 'progressed',
            'realtime-reconnected' => '$refresh',   // back-fill whatever the socket missed (PRD §9.7)
        ] : [];
    }

    /** @param  array<string, mixed>  $payload */
    public function progressed(array $payload): void
    {
        if (($payload['batch'] ?? null) !== $this->code) {
            $this->skipRender();
        }
    }

    public function updatedFilter(): void
    {
        $this->filter = in_array($this->filter, self::FILTERS, true) ? $this->filter : 'all';
        $this->resetPage();
    }

    public function saveMapping(SaveColumnMapping $save): void
    {
        $this->resetErrorBag();

        try {
            $save->handle($this->user(), $this->code, $this->mapping);
        } catch (ImportRefused $refused) {
            $this->dispatch('toast', type: 'error', message: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', message: __('Thanks — checking the rows now.'));
    }

    public function render(): View
    {
        $batch = $this->batch();
        $ready = $batch->status === ImportBatchStatus::Ready;

        $rows = $ready
            ? BrokerImportRow::query()->where('batch_id', $batch->id)
                ->when($this->filter !== 'all', fn ($q) => $q->where('status', ImportRowStatus::from(strtoupper($this->filter))->value))
                ->orderBy('row_number')
                ->paginate(self::PER_PAGE)
            : null;

        $done = $batch->rows_valid + $batch->rows_warning + $batch->rows_error;

        return view('livewire.broker.import.batch-review', [
            'batch' => $batch,
            'rows' => $rows,
            'percent' => $batch->rows_total > 0 ? (int) min(100, round($done * 100 / $batch->rows_total)) : 0,
            'working' => in_array($batch->status, [ImportBatchStatus::Uploaded, ImportBatchStatus::Validating], true),
            'poll' => Feature::active(Flag::RealtimePollingFallback),
            'fields' => array_map(fn (array $c): array => ['header' => $c[0], 'required' => $c[1]], ImportColumns::COLUMNS),
            'duplicateOf' => $batch->duplicate_of_batch_id !== null
                ? BrokerImportBatch::query()->whereKey($batch->duplicate_of_batch_id)->value('code')
                : null,
        ])->layout('layouts::broker', ['seo' => SeoData::private($batch->code.' | Oppam Matrimony')]);
    }

    private function batch(): BrokerImportBatch
    {
        $staff = app(BureauGate::class)->authorize($this->user(), BureauPermission::ProfilesImport);
        abort_unless(Feature::active(Flag::BrokerBulkImport), 404);

        return BrokerImportBatch::query()->where('broker_id', $staff->broker_id)->where('code', $this->code)->firstOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
