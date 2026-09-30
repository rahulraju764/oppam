<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** An occupation option. */
final class Occupation extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\OccupationFactory> */
    use HasFactory;

    protected $table = 'master_occupations';
}
