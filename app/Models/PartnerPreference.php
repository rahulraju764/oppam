<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Wizard step 4 — partner preferences (M02). Empty id lists mean "any".
 *
 * @property int|null $age_min
 * @property int|null $age_max
 * @property int|null $height_min_cm
 * @property int|null $height_max_cm
 * @property list<string>|null $marital_statuses
 * @property list<string>|null $physical_statuses
 * @property list<int>|null $religion_ids
 * @property list<int>|null $caste_ids
 * @property list<int>|null $mother_tongue_ids
 * @property list<int>|null $star_ids
 * @property list<int>|null $education_ids
 * @property list<int>|null $occupation_ids
 * @property int|null $min_income_band_id
 * @property list<int>|null $country_ids
 * @property list<int>|null $district_ids
 * @property list<int>|null $diet_option_ids
 * @property string|null $about_partner
 */
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
