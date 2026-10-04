<?php

declare(strict_types=1);

namespace App\Data\Search;

use App\Enums\CreatedFor;
use App\Enums\EmployerType;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Enums\SearchSort;
use App\ValueObjects\HeightCm;

/**
 * Everything a member can ask search for (M04 filters + sort), built from untrusted input (the
 * URL) by fromInput(): numbers are clamped, ids are positive integers, lists are capped, enum
 * values must exist — anything else is simply dropped. The raw values stay in the URL, so a
 * copied URL reproduces the search.
 */
final readonly class SearchCriteria
{
    public const AGE_MIN = 18;

    public const AGE_MAX = 70;

    public const MAX_LIST = 30;

    public const ACTIVE_WITHIN = ['1d' => 1, '7d' => 7, '30d' => 30];

    /**
     * @param  list<MaritalStatus>  $maritalStatuses
     * @param  list<int>  $motherTongueIds
     * @param  list<int>  $casteIds
     * @param  list<int>  $starIds
     * @param  list<int>  $districtIds
     * @param  list<int>  $occupationIds
     */
    public function __construct(
        public ?int $ageMin = null,
        public ?int $ageMax = null,
        public ?int $heightMin = null,
        public ?int $heightMax = null,
        public array $maritalStatuses = [],
        public bool $noChildren = false,
        public ?PhysicalStatus $physicalStatus = null,
        public array $motherTongueIds = [],
        public ?int $religionId = null,
        public array $casteIds = [],
        public bool $includeCasteNoBar = false,
        public ?string $subCaste = null,
        public array $starIds = [],
        public ?int $rasiId = null,
        public bool $noDosham = false,
        public ?int $countryId = null,
        public ?int $stateId = null,
        public array $districtIds = [],
        public bool $nriOnly = false,
        public ?string $citizenship = null,
        public ?int $educationMinId = null,
        public array $occupationIds = [],
        public ?EmployerType $employerType = null,
        public ?int $incomeMinId = null,
        public ?int $familyStatusId = null,
        public ?int $familyTypeId = null,
        public ?int $familyValuesId = null,
        public ?int $dietId = null,
        public ?int $smokingId = null,
        public ?int $drinkingId = null,
        public bool $withPhoto = false,
        public bool $verifiedOnly = false,
        public bool $premiumOnly = false,
        public ?string $createdBy = null,
        public ?string $activeWithin = null,
        public bool $newlyJoined = false,
        public bool $hideViewed = false,
        public SearchSort $sort = SearchSort::Relevance,
    ) {}

    /** @param  array<string, mixed>  $in */
    public static function fromInput(array $in): self
    {
        $int = function (string $key, int $min = 1, int $max = PHP_INT_MAX) use ($in): ?int {
            $v = $in[$key] ?? null;
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $v = (int) $v;

                return $v >= $min && $v <= $max ? $v : null;
            }

            return null;
        };
        $ids = function (string $key) use ($in): array {
            $v = $in[$key] ?? [];
            $v = is_array($v) ? $v : (is_scalar($v) ? explode(',', (string) $v) : []);
            $out = [];
            foreach (array_slice($v, 0, self::MAX_LIST) as $item) {
                if ((is_int($item) || (is_string($item) && ctype_digit($item))) && (int) $item > 0) {
                    $out[] = (int) $item;
                }
            }

            return array_values(array_unique($out));
        };
        $bool = fn (string $key): bool => in_array($in[$key] ?? null, [true, 1, '1', 'true', 'on'], true);
        $text = function (string $key) use ($in): ?string {
            $v = $in[$key] ?? null;
            $v = is_string($v) ? trim(mb_substr($v, 0, 60)) : '';

            return $v === '' ? null : $v;
        };

        $marital = [];
        $rawMarital = $in['marital'] ?? [];
        foreach (is_array($rawMarital) ? $rawMarital : explode(',', is_string($rawMarital) ? $rawMarital : '') as $value) {
            $status = is_string($value) ? MaritalStatus::tryFrom($value) : null;
            if ($status !== null) {
                $marital[$status->value] = $status;
            }
        }

        $ageMin = $int('age_min', self::AGE_MIN, self::AGE_MAX);
        $ageMax = $int('age_max', self::AGE_MIN, self::AGE_MAX);
        $heightMin = $int('height_min', HeightCm::MIN, HeightCm::MAX);
        $heightMax = $int('height_max', HeightCm::MIN, HeightCm::MAX);
        $createdBy = is_string($in['created_by'] ?? null) && in_array($in['created_by'], ['self', 'family'], true) ? $in['created_by'] : null;
        $active = is_string($in['active'] ?? null) && array_key_exists($in['active'], self::ACTIVE_WITHIN) ? $in['active'] : null;

        return new self(
            ageMin: $ageMin !== null && $ageMax !== null ? min($ageMin, $ageMax) : $ageMin,
            ageMax: $ageMin !== null && $ageMax !== null ? max($ageMin, $ageMax) : $ageMax,
            heightMin: $heightMin !== null && $heightMax !== null ? min($heightMin, $heightMax) : $heightMin,
            heightMax: $heightMin !== null && $heightMax !== null ? max($heightMin, $heightMax) : $heightMax,
            maritalStatuses: array_values($marital),
            noChildren: $bool('no_children'),
            physicalStatus: is_string($in['physical'] ?? null) ? PhysicalStatus::tryFrom($in['physical']) : null,
            motherTongueIds: $ids('mother_tongue'),
            religionId: $int('religion'),
            casteIds: $ids('caste'),
            includeCasteNoBar: $bool('caste_no_bar'),
            subCaste: $text('sub_caste'),
            starIds: $ids('star'),
            rasiId: $int('rasi'),
            noDosham: $bool('no_dosham'),
            countryId: $int('country'),
            stateId: $int('state'),
            districtIds: $ids('district'),
            nriOnly: $bool('nri'),
            citizenship: $text('citizenship'),
            educationMinId: $int('education_min'),
            occupationIds: $ids('occupation'),
            employerType: is_string($in['employer'] ?? null) ? EmployerType::tryFrom($in['employer']) : null,
            incomeMinId: $int('income_min'),
            familyStatusId: $int('family_status'),
            familyTypeId: $int('family_type'),
            familyValuesId: $int('family_values'),
            dietId: $int('diet'),
            smokingId: $int('smoking'),
            drinkingId: $int('drinking'),
            withPhoto: $bool('photo'),
            verifiedOnly: $bool('verified'),
            premiumOnly: $bool('premium'),
            createdBy: $createdBy,
            activeWithin: $active,
            newlyJoined: $bool('new'),
            hideViewed: $bool('hide_viewed'),
            sort: is_string($in['sort'] ?? null) ? (SearchSort::tryFrom($in['sort']) ?? SearchSort::Relevance) : SearchSort::Relevance,
        );
    }

    /**
     * The "created by" values that mean a family member made the profile.
     *
     * @return list<string>
     */
    public static function familyCreators(): array
    {
        return array_values(array_map(fn (CreatedFor $c): string => $c->value, array_filter(CreatedFor::cases(), fn (CreatedFor $c): bool => $c !== CreatedFor::Self)));
    }
}
