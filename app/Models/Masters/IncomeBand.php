<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An annual income band; bounds in paise (null max = open-ended top band).
 *
 * @property int $min_paise
 * @property int|null $max_paise
 */
final class IncomeBand extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\IncomeBandFactory> */
    use HasFactory;

    protected $table = 'master_income_bands';

    /** @var list<string> */
    protected $fillable = ['code', 'label', 'label_ml', 'sort_order', 'is_active', 'min_paise', 'max_paise'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [...parent::casts(), 'min_paise' => 'integer', 'max_paise' => 'integer'];
    }
}
