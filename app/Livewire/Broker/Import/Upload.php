<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Import;

use App\Actions\Broker\Import\StoreImportBatch;
use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportFileInspector;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ParseImportBatch;
use App\Models\Broker;
use App\Models\User;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * /broker/import (§11A B.9 step 1, B.11 `profiles.import`): download the template, upload the sheet
 * (+ an optional photo zip) and land on the batch page with its live validation. The files are
 * checked by StoreImportBatch from their bytes; this screen only collects them. States: the
 * `broker.bulk_import` flag off, imports paused by A07.
 */
final class Upload extends Component
{
    use WithFileUploads;

    /** @var UploadedFile|null */
    public $sheet = null;

    /** @var UploadedFile|null */
    public $photos = null;

    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::ProfilesImport);
    }

    public function startImport(StoreImportBatch $store): mixed
    {
        $this->validate([
            'sheet' => ['required', 'file', 'max:'.ImportFileInspector::SHEET_MAX_KB],
            'photos' => ['nullable', 'file', 'max:'.ImportFileInspector::ZIP_MAX_KB],
        ], ['sheet.required' => __('Choose the filled-in sheet (.xlsx or .csv).')]);

        try {
            /** @var UploadedFile $sheet */
            $sheet = $this->sheet;
            $batch = $store->handle($this->user(), $sheet, $this->photos instanceof UploadedFile ? $this->photos : null);
        } catch (ImportRefused $refused) {
            $this->addError('sheet', $refused->getMessage());

            return null;
        } finally {
            // The plain temporary uploads never outlive this request: the batch keeps encrypted copies (rule 7).
            foreach ([$this->sheet, $this->photos] as $temporary) {
                if ($temporary instanceof TemporaryUploadedFile) {
                    $temporary->delete();
                }
            }
            $this->reset('sheet', 'photos');
        }

        return $this->redirectRoute('broker.import.batch', ['batch' => $batch->code], navigate: true);
    }

    public function render(BureauGate $gate): View
    {
        $staff = $gate->authorize($this->user(), BureauPermission::ProfilesImport);
        /** @var Broker $broker */
        $broker = $staff->broker;

        return view('livewire.broker.import.upload', [
            'enabled' => Feature::active(Flag::BrokerBulkImport),
            'paused' => $broker->imports_paused_at !== null,
            'maxRows' => ParseImportBatch::MAX_ROWS,
        ])->layout('layouts::broker', ['seo' => SeoData::private('Bulk import | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
