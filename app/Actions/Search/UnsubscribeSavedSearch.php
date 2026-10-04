<?php

declare(strict_types=1);

namespace App\Actions\Search;

use App\Enums\AlertFrequency;
use App\Models\SavedSearch;

/**
 * Turn off a saved search's alert emails from the link in the email (M04). The random
 * alert_token is the only credential (the member may not be signed in); null when it matches
 * nothing. Idempotent.
 */
final class UnsubscribeSavedSearch
{
    public function find(string $token): ?SavedSearch
    {
        if (preg_match('/^[A-Za-z0-9]{48}$/', $token) !== 1) {
            return null;
        }

        return SavedSearch::query()->where('alert_token', $token)->first();
    }

    public function handle(string $token): ?SavedSearch
    {
        $search = $this->find($token);

        if ($search !== null && $search->alert_frequency !== AlertFrequency::Off) {
            $search->alert_frequency = AlertFrequency::Off;
            $search->save();
        }

        return $search;
    }
}
