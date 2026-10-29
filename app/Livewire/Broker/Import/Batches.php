<?php

declare(strict_types=1);

namespace App\Livewire\Broker\Import;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Models\BrokerImportBatch;
use App\Models\User;
use App\Support\Facades\Feature;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * /broker/import/batches (§11A B.9 "Batch history"): the bureau's batches, newest first — file type,
 * uploader, date, row counts (valid / warning / error / imported), status and the A04 approval rate —
 * each opening its batch page. Only the caller's bureau's (scoped by its id). Like the batch page it
 * opens with the role part of `profiles.import`, so a bureau whose KYC was revoked can still find a
 * batch to cancel (B.9).
 */
final class Batches extends Component
{
    use WithPagination;

    private const PER_PAGE = 25;

    public function mount(BureauGate $gate): void
    {
        $gate->authorizeRole($this->user(), BureauPermission::ProfilesImport);
        abort_unless(Feature::active(Flag::BrokerBulkImport), 404);
    }

    public function render(BureauGate $gate): View
    {
        $staff = $gate->authorizeRole($this->user(), BureauPermission::ProfilesImport);

        $batches = BrokerImportBatch::query()
            ->where('broker_id', $staff->broker_id)
            ->with(['uploaderStaff.user', 'uploaderStaff.broker'])   // displayName() reads both
            ->latest('created_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return view('livewire.broker.import.batches', ['batches' => $batches])
            ->layout('layouts::broker', ['seo' => SeoData::private('Import batches | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
