<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** A rasi / moon sign (12). */
final class Rasi extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\RasiFactory> */
    use HasFactory;

    protected $table = 'master_rasis';
}
