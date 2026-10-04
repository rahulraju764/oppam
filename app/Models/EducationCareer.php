<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EmployerType;
use App\Models\Concerns\KeyedByProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Wizard step 2 — education & career (M02).
 *
 * @property int|null $education_id
 * @property int|null $occupation_id
 * @property int|null $income_band_id
 */
final class EducationCareer extends Model
{
    /** @use HasFactory<\Database\Factories\EducationCareerFactory> */
    use HasFactory, KeyedByProfile;

    protected $table = 'education_careers';

    /** @var list<string> */
    protected $fillable = [
        'education_id',
        'education_detail',
        'occupation_id',
        'employer_type',
        'employer_name',
        'income_band_id',
        'citizenship',
        'visa_status',
        'current_country_id',
        'current_state_id',
        'current_district_id',
        'current_city',
        'permanent_country_id',
        'permanent_state_id',
        'permanent_district_id',
        'permanent_city',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'employer_type' => EmployerType::class,
        ];
    }
}
