<?php

declare(strict_types=1);

namespace App\Domain\Chat;

use App\Models\PrivacySetting;

/**
 * Read receipts are symmetric (R-M07-7): a member who turns them off doesn't send them and doesn't
 * see anyone else's. `privacy_settings.read_receipts` (default on; no row = on). Blue ticks are
 * shown only when BOTH members have them on; a reader with them off is never announced.
 */
final class ReadReceipts
{
    public function enabled(string $profileId): bool
    {
        return (bool) (PrivacySetting::query()->whereKey($profileId)->value('read_receipts') ?? true);
    }

    public function shownBetween(string $profileIdA, string $profileIdB): bool
    {
        return $this->enabled($profileIdA) && $this->enabled($profileIdB);
    }
}
