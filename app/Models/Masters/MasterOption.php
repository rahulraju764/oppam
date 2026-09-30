<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A generic option (diet, smoking, drinking, complexion, body type, family type/status/values).
 *
 * @property string $group
 */
final class MasterOption extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\MasterOptionFactory> */
    use HasFactory;

    protected $table = 'master_options';

    /** @var list<string> */
    protected $fillable = ['group', 'code', 'label', 'label_ml', 'sort_order', 'is_active'];

    /** @param Builder<self> $query */
    public function scopeInGroup(Builder $query, string $group): void
    {
        $query->where('group', $group);
    }
}
