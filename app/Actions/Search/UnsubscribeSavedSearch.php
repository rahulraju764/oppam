<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Enums\AlertFrequency;
use App\Models\SavedSearch;

final class UnsubscribeSavedSearch
{
    public function handle(SavedSearch $savedSearch): void
    {
        $savedSearch->update(['alert_frequency' => AlertFrequency::Off]);
    }
}
