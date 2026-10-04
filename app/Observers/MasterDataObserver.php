<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Masters\MasterRecord;
use App\Services\Masters\Masters;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Any write to a master table invalidates every cached master list, so wizard and search
 * dropdowns reflect an A11 edit within seconds (PRD A11). Registered in AppServiceProvider.
 */
final class MasterDataObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly Masters $masters) {}

    public function saved(MasterRecord $record): void
    {
        $this->masters->flush();
    }

    public function deleted(MasterRecord $record): void
    {
        $this->masters->flush();
    }
}
