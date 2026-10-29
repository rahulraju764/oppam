<?php

declare(strict_types=1);

namespace App\Data\Broker;

use App\Data\Profile\AboutDetailsData;
use App\Data\Profile\BasicDetailsData;
use App\Data\Profile\CareerDetailsData;
use App\Enums\PhotoVisibility;

/**
 * One validated import row (the `values` ImportRowValidator stored) as the single form's own DTOs,
 * so the import writes through exactly the same Actions as "Add profile" (§11A B.9 step 5, R-M13-28).
 */
final readonly class ImportedRowData
{
    /** @param  list<string>  $photoFiles  file names in the batch zip, first = primary */
    public function __construct(
        public ManagedProfileData $managed,
        public BasicDetailsData $basic,
        public CareerDetailsData $career,
        public AboutDetailsData $about,
        public array $photoFiles,
    ) {}

    /** @param  array<string, mixed>  $v  the row's parsed values */
    public static function fromValues(array $v): self
    {
        $int = fn (string $k): ?int => isset($v[$k]) && is_numeric($v[$k]) ? (int) $v[$k] : null;
        $str = fn (string $k): ?string => isset($v[$k]) && is_scalar($v[$k]) && (string) $v[$k] !== '' ? (string) $v[$k] : null;

        return new self(
            managed: new ManagedProfileData((string) ($v['client_mobile'] ?? ''), (bool) ($v['client_consent'] ?? false)),
            basic: new BasicDetailsData(
                first_name: $str('first_name'), last_name: $str('last_name'), gender: $str('gender'), dob: $str('dob'),
                height_cm: $int('height_cm'), weight_kg: $int('weight_kg'), marital_status: $str('marital_status'),
                children_count: $int('children_count'), physical_status: $str('physical_status'),
                religion_id: $int('religion_id'), caste_id: $int('caste_id'), caste_no_bar: (bool) ($v['caste_no_bar'] ?? false),
                sub_caste: $str('sub_caste'), mother_tongue_id: $int('mother_tongue_id'), star_id: $int('star_id'),
                rasi_id: $int('rasi_id'), chovva_dosham: $str('chovva_dosham'), papa_dosham: $str('papa_dosham'),
            ),
            career: new CareerDetailsData(
                education_id: $int('education_id'), education_detail: $str('education_detail'), employer_type: $str('employer_type'),
                occupation_id: $int('occupation_id'), employer_name: null, income_band_id: $int('income_band_id'),
                current_country_id: $int('current_country_id'), current_state_id: $int('current_state_id'),
                current_district_id: $int('current_district_id'), current_city: $str('current_city'),
                citizenship: null, visa_status: null, permanent_country_id: null, permanent_state_id: null,
                permanent_district_id: null, permanent_city: null,
            ),
            about: new AboutDetailsData(
                about: $str('about'), diet_option_id: null, smoking_option_id: null, drinking_option_id: null,
                hobbies: [], photo_visibility: PhotoVisibility::AllMembers->value,   // the form's own default (AboutForm)
            ),
            photoFiles: array_values(array_filter(is_array($v['photo_files'] ?? null) ? $v['photo_files'] : [], 'is_string')),
        );
    }
}
