<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Import;

use App\Actions\Broker\Import\AttestBatchConsent;
use App\Actions\Broker\Import\CancelImportBatch;
use App\Actions\Broker\Import\FixImportRow;
use App\Actions\Broker\Import\ReuploadImportErrors;
use App\Actions\Broker\Import\SaveColumnMapping;
use App\Actions\Broker\Import\StartBatchImport;
use App\Actions\Broker\Import\SubmitImportedProfiles;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\CellParser;
use App\Domain\Broker\Import\ImportAllowance;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\BureauPermission;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\Broker;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Models\User;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * /broker/import/{batch} (§11A B.9 steps 2–6): live validation (ImportProgress on `broker.{broker}`),
 * the column-matching form while MAPPING; once READY the row preview (badges, `#[Url]` filter,
 * messages) with inline cell fixes, the fixed errors file re-upload, the uploader's consent
 * attestation and Import; then live import progress, the imported profiles, "Submit all complete",
 * and Cancel. Every change goes through an Action (they authorize again).
 *
 * The page opens with the ROLE part of `profiles.import` (route `bureau:profiles.import,role`): a
 * bureau whose KYC was revoked still sees the batch summary and may cancel it (B.9), but no client
 * rows and no other action (R-M13-21). The code is locked and looked up inside the caller's bureau.
 */
final class BatchReview extends Component
{
    use WithFileUploads;
    use WithPagination;

    public const FILTERS = ['all', 'valid', 'warning', 'error', 'imported', 'skipped'];

    private const PER_PAGE = 25;

    #[Locked]
    public string $code = '';

    #[Url(as: 'rows')]
    public string $filter = 'all';

    /** @var array<string, string> field => sheet header (the mapping form) */
    public array $mapping = [];

    public bool $consent = false;

    /** @var UploadedFile|null the fixed errors file */
    public $fixes = null;

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
            'echo-private:broker.'.$staff->broker_id.',.import.completed' => 'completed',
            'echo-private:broker.'.$staff->broker_id.',.imports.paused' => '$refresh',
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

    /** @param  array<string, mixed>  $payload */
    public function completed(array $payload): void
    {
        if (($payload['batch'] ?? null) !== $this->code) {
            $this->skipRender();

            return;
        }

        $this->dispatch('toast', type: 'success', title: trans_choice('Import finished: :count profile added as a draft.|Import finished: :count profiles added as drafts.', (int) ($payload['imported'] ?? 0)));
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
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: __('Thanks — checking the rows now.'));
    }

    /** B.9 step 3: one cell fixed inline; the row is checked again (FixImportRow). */
    public function fixCell(FixImportRow $fix, int $row, string $header, string $value): void
    {
        try {
            $fixed = $fix->handle($this->user(), $this->code, $row, $header, $value);
        } catch (ImportRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->dispatch('toast', type: 'danger', title: (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->dispatch('toast', type: $fixed->status === ImportRowStatus::Error ? 'info' : 'success', title: $fixed->status === ImportRowStatus::Error
            ? __('Saved. Row :n still has something to fix.', ['n' => $row])
            : __('Row :n is ready.', ['n' => $row]));
    }

    /** B.9 step 3: the fixed errors file back into this batch (ReuploadImportErrors). */
    public function uploadFixes(ReuploadImportErrors $reupload): void
    {
        $this->resetErrorBag();
        $this->validate(['fixes' => ['required', 'file', 'max:10240']], ['fixes.required' => __('Choose the fixed file.')]);

        try {
            /** @var UploadedFile $file */
            $file = $this->fixes;
            $count = $reupload->handle($this->user(), $this->code, $file);
        } catch (ImportRefused $refused) {
            $this->addError('fixes', $refused->getMessage());

            return;
        } catch (ValidationException $e) {
            $this->addError('fixes', (string) collect($e->errors())->flatten()->first());

            return;
        } finally {
            if ($this->fixes instanceof TemporaryUploadedFile) {
                $this->fixes->delete();   // the batch keeps an encrypted copy (rule 7)
            }
            $this->reset('fixes');
        }

        $this->dispatch('toast', type: 'success', title: trans_choice(':count row updated and checked again.|:count rows updated and checked again.', $count));
    }

    /** B.9 step 4 (R-M13-27): the uploader's attestation. */
    public function attest(AttestBatchConsent $attest): void
    {
        $this->resetErrorBag();
        if (! $this->consent) {
            $this->addError('consent', __('Tick the box to confirm every client in this file has agreed.'));

            return;
        }

        $this->runAction(fn () => $attest->handle($this->user(), $this->code), __('Consent confirmed. You can import now.'));
    }

    /** B.9 step 5. */
    public function import(StartBatchImport $start): void
    {
        $this->runAction(fn () => $start->handle($this->user(), $this->code), __('Importing — you can leave this page; we’ll tell you when it’s done.'));
    }

    /** B.9 step 6: every complete imported draft to A04. */
    public function submitAll(SubmitImportedProfiles $submit): void
    {
        $result = $submit->handle($this->user(), $this->code);

        $this->dispatch('toast', type: $result->submitted > 0 ? 'success' : 'info', title: trim(
            trans_choice(':count profile sent for review.|:count profiles sent for review.', $result->submitted).' '.
            ($result->incomplete > 0 ? trans_choice(':count still needs details before it can be sent.|:count still need details before they can be sent.', $result->incomplete) : '')
        ));
    }

    public function cancel(CancelImportBatch $cancel): void
    {
        $this->runAction(fn () => $cancel->handle($this->user(), $this->code), __('Batch closed.'));
    }

    public function render(BureauGate $gate, ImportAllowance $allowance): View
    {
        $batch = $this->batch();
        $staff = $gate->staff($this->user());
        /** @var Broker $broker */
        $broker = $batch->broker()->firstOrFail();
        $canHandle = $gate->allows($this->user(), BureauPermission::ProfilesImport);
        $isUploader = $batch->uploaded_by_user_id === $this->user()->id;
        $showRows = $canHandle && in_array($batch->status, [ImportBatchStatus::Ready, ImportBatchStatus::Importing, ImportBatchStatus::Completed], true);

        $rows = $showRows
            ? BrokerImportRow::query()->where('batch_id', $batch->id)
                ->when($this->filter !== 'all', fn ($q) => $q->where('status', ImportRowStatus::from(strtoupper($this->filter))->value))
                ->orderBy('row_number')
                ->paginate(self::PER_PAGE)
            : null;

        // Imported rows link to their profile — only those this login may open (assigned-only scope).
        $profiles = $rows !== null && $staff !== null
            ? Profile::query()->whereIn('id', $rows->getCollection()->pluck('imported_profile_id')->filter()->all())
                ->where('managed_by_broker_id', $staff->broker_id)->whereNull('claimed_at')
                ->when($staff->isAssignedOnly(), fn ($q) => $q->where('assigned_staff_id', $staff->id))
                ->pluck('code', 'id')->all()
            : [];

        // The Fix editor: per open row of a READY batch, its flagged cells (header => current text).
        $fixable = [];
        if ($rows !== null && $batch->status === ImportBatchStatus::Ready) {
            foreach ($rows as $row) {
                if (in_array($row->status, [ImportRowStatus::Error, ImportRowStatus::Warning], true)) {
                    $fixable[$row->row_number] = self::fixableCells($batch, $row);
                }
            }
        }

        $checked = $batch->rows_valid + $batch->rows_warning + $batch->rows_error + $batch->rows_imported + $batch->rows_skipped;
        $toImport = $batch->rows_valid + $batch->rows_warning;

        return view('livewire.broker.import.batch-review', [
            'batch' => $batch,
            'rows' => $rows,
            'profiles' => $profiles,
            'fixable' => $fixable,
            'canHandle' => $canHandle,
            'canSubmit' => $gate->allows($this->user(), BureauPermission::ProfilesSubmit),
            'canCancel' => $batch->isCancellable() && ($isUploader || in_array($staff?->role, [BureauRole::Owner, BureauRole::Manager], true)),
            'isUploader' => $isUploader,
            'paused' => $broker->imports_paused_at !== null,
            'pauseReason' => $broker->imports_paused_reason,
            'remaining' => $canHandle && $batch->status === ImportBatchStatus::Ready ? $allowance->remainingToday($broker) : null,
            'toImport' => $toImport,
            'percent' => match (true) {
                $batch->status === ImportBatchStatus::Importing => ($batch->rows_imported + $toImport) > 0 ? (int) round($batch->rows_imported * 100 / ($batch->rows_imported + $toImport)) : 0,
                $batch->rows_total > 0 => (int) min(100, round($checked * 100 / $batch->rows_total)),
                default => 0,
            },
            'working' => in_array($batch->status, [ImportBatchStatus::Uploaded, ImportBatchStatus::Validating, ImportBatchStatus::Importing], true),
            'poll' => Feature::active(Flag::RealtimePollingFallback),
            'fields' => array_map(fn (array $c): array => ['header' => $c[0], 'required' => $c[1]], ImportColumns::COLUMNS),
            'duplicateOf' => $batch->duplicate_of_batch_id !== null
                ? BrokerImportBatch::query()->whereKey($batch->duplicate_of_batch_id)->value('code')
                : null,
        ])->layout('layouts::broker', ['seo' => SeoData::private($batch->code.' | Oppam Matrimony')]);
    }

    /**
     * The Fix editor's cells for a row: the cells its messages point at (via the column map), as
     * header => current text (the stored, neutralised text shown back without its apostrophe).
     *
     * @return array<string, string>
     */
    private static function fixableCells(BrokerImportBatch $batch, BrokerImportRow $row): array
    {
        $map = is_array($batch->column_map) ? $batch->column_map : [];
        $raw = is_array($row->data['raw'] ?? null) ? $row->data['raw'] : [];
        $cells = [];
        // Messages name the TEMPLATE header (ImportRowValidator); the sheet may call that column something else.
        $fieldOf = [];
        foreach (ImportColumns::COLUMNS as $key => [$templateHeader]) {
            $fieldOf[$templateHeader] = $key;
        }
        foreach ($row->messages ?? [] as $message) {
            $named = (string) ($message['field'] ?? '');
            $header = $map[$fieldOf[$named] ?? $named] ?? null;
            if (is_string($header) && $header !== '') {
                $cells[$header] = CellParser::restore((string) ($raw[$header] ?? ''));
            }
        }

        return $cells;
    }

    /** Run an Action that may refuse: its message as an error toast, else the success toast. */
    private function runAction(callable $action, string $success): void
    {
        try {
            $action();
        } catch (ImportRefused $refused) {
            $this->dispatch('toast', type: 'danger', title: $refused->getMessage());

            return;
        }

        $this->dispatch('toast', type: 'success', title: $success);
    }

    private function batch(): BrokerImportBatch
    {
        $staff = app(BureauGate::class)->authorizeRole($this->user(), BureauPermission::ProfilesImport);
        abort_unless(Feature::active(Flag::BrokerBulkImport), 404);

        return $this->find($staff);
    }

    private function find(BrokerStaff $staff): BrokerImportBatch
    {
        return BrokerImportBatch::query()->where('broker_id', $staff->broker_id)->where('code', $this->code)->firstOrFail();
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
