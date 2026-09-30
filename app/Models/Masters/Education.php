<?php

declare(strict_types=1);

namespace App\Models\Masters;

use Illuminate\Database\Eloquent\Factories\HasFactory;

/** A highest-education option. */
final class Education extends MasterRecord
{
    /** @use HasFactory<\Database\Factories\Masters\EducationFactory> */
    use HasFactory;

    protected $table = 'master_education';
}
