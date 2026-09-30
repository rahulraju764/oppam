<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A district of a state.
 *
 * @property int $state_id
 */
final class District extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\DistrictFactory> */
    use HasFactory;

    protected $table = 'master_districts';

    /** @var list<string> */
    protected $fillable = ['state_id', 'code', 'label', 'label_ml', 'sort_order', 'is_active'];

    /** @return BelongsTo<State, $this> */
    public function state(): BelongsTo
    {
        return $this->belongsTo(State::class);
    }

    /** @param Builder<self> $query */
    public function scopeForState(Builder $query, int $stateId): void
    {
        $query->where('state_id', $stateId);
    }
}
