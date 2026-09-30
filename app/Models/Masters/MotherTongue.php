<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** A mother tongue. */
final class MotherTongue extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\MotherTongueFactory> */
    use HasFactory;

    protected $table = 'master_mother_tongues';
}
