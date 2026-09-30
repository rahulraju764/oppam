<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** A nakshatra (27). */
final class Star extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\StarFactory> */
    use HasFactory;

    protected $table = 'master_stars';
}
