<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A state / union territory of a country.
 *
 * @property int $country_id
 */
final class State extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\StateFactory> */
    use HasFactory;

    protected $table = 'master_states';

    /** @var list<string> */
    protected $fillable = ['country_id', 'code', 'label', 'label_ml', 'sort_order', 'is_active'];

    /** @return BelongsTo<Country, $this> */
    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /** @param Builder<self> $query */
    public function scopeForCountry(Builder $query, int $countryId): void
    {
        $query->where('country_id', $countryId);
    }
}
