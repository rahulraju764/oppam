<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Wizard step 3 — family (M02). */
final class FamilyDetail extends Model
{
    /** @use HasFactory<\Database\Factories\FamilyDetailFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'family_details';

    /** @var list<string> */
    protected $fillable = [
        'father_name',
        'father_occupation',
        'mother_name',
        'mother_occupation',
        'brothers_married',
        'brothers_unmarried',
        'sisters_married',
        'sisters_unmarried',
        'family_status_option_id',
        'family_type_option_id',
        'family_values_option_id',
        'native_place',
        'about_family',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'brothers_married' => 'integer',
            'brothers_unmarried' => 'integer',
            'sisters_married' => 'integer',
            'sisters_unmarried' => 'integer',
        ];
    }
}
