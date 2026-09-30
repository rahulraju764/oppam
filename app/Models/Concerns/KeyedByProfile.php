<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * For the one-row-per-profile tables (wizard steps, privacy settings): profile_id is the
 * primary key, set by the Action that creates the row — never from input.
 *
 * @property string $profile_id
 */
trait KeyedByProfile
{
    public function initializeKeyedByProfile(): void
    {
        $this->primaryKey = 'profile_id';
        $this->incrementing = false;
        $this->keyType = 'string';
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
