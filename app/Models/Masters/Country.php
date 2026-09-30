<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A country (ISO 3166-1 alpha-2 code). */
final class Country extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\CountryFactory> */
    use HasFactory;

    protected $table = 'master_countries';

    /** @return HasMany<State, $this> */
    public function states(): HasMany
    {
        return $this->hasMany(State::class);
    }
}
