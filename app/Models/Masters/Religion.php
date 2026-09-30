<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A religion (PRD §7.1). Castes belong to one religion. */
final class Religion extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\ReligionFactory> */
    use HasFactory;

    protected $table = 'master_religions';

    /** @return HasMany<Caste, $this> */
    public function castes(): HasMany
    {
        return $this->hasMany(Caste::class);
    }
}
