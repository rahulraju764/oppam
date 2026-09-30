<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Wizard step 4 — partner preferences (M02). Empty id lists mean "any". */
final class PartnerPreference extends Model
{
    /** @use HasFactory<\Database\Factories\PartnerPreferenceFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'partner_preferences';

    /** @var list<string> */
    protected $fillable = [
        'age_min',
        'age_max',
        'height_min_cm',
        'height_max_cm',
        'marital_statuses',
        'physical_statuses',
        'religion_ids',
        'caste_ids',
        'mother_tongue_ids',
        'star_ids',
        'education_ids',
        'occupation_ids',
        'min_income_band_id',
        'country_ids',
        'district_ids',
        'diet_option_ids',
        'about_partner',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'marital_statuses' => 'array',
            'physical_statuses' => 'array',
            'religion_ids' => 'array',
            'caste_ids' => 'array',
            'mother_tongue_ids' => 'array',
            'star_ids' => 'array',
            'education_ids' => 'array',
            'occupation_ids' => 'array',
            'country_ids' => 'array',
            'district_ids' => 'array',
            'diet_option_ids' => 'array',
        ];
    }
}
