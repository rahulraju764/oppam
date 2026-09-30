<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared shape of every master_* table (PRD A11): code, label, label_ml, sort_order, is_active.
 * Read through App\Services\Masters\Masters (cached); writes happen only in A11 (P1.8), and
 * every write bumps the masters cache version (MasterDataObserver).
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $label_ml
 * @property int $sort_order
 * @property bool $is_active
 */
abstract class MasterRecord extends Model
{
    /** @var list<string> */
    protected $fillable = ['code', 'label', 'label_ml', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @param Builder<static> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /** @param Builder<static> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy($this->qualifyColumn('sort_order'))->orderBy($this->qualifyColumn('label'));
    }
}
