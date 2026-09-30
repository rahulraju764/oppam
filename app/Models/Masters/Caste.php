<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A caste / denomination, always under one religion (caste list is filtered by religion).
 *
 * @property int $religion_id
 */
final class Caste extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\CasteFactory> */
    use HasFactory;

    protected $table = 'master_castes';

    /** @var list<string> */
    protected $fillable = ['religion_id', 'code', 'label', 'label_ml', 'sort_order', 'is_active'];

    /** @return BelongsTo<Religion, $this> */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /** @param Builder<self> $query */
    public function scopeForReligion(Builder $query, int $religionId): void
    {
        $query->where('religion_id', $religionId);
    }
}
