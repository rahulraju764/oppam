<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use App\Data\Masters\MasterItem;
use App\Data\Matching\PreferenceCheck;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Models\PartnerPreference;
use App\Models\Profile;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;

/**
 * How a candidate profile fits someone's partner preferences (M03 "You match X of Y", the
 * contact filter R-M03-2, later search ranking and daily matches). Only preferences that were
 * actually set count — an empty list means "any" and is not a line. A candidate field that is
 * unknown counts as not matching. Pure reads; master labels come from the cached Masters lists.
 */
final class PreferenceMatcher
{
    public function __construct(private readonly Masters $masters) {}

    /** @return list<PreferenceCheck> */
    public function check(Profile $candidate, ?PartnerPreference $preference): array
    {
        if ($preference === null) {
            return [];
        }

        $career = $candidate->educationCareer;
        $checks = [];

        if ($preference->age_min !== null || $preference->age_max !== null) {
            $age = $candidate->age();
            $checks[] = new PreferenceCheck(__('Age'), __(':min – :max years', ['min' => $preference->age_min ?? 18, 'max' => $preference->age_max ?? 70]),
                $age !== null && $age >= ($preference->age_min ?? 0) && $age <= ($preference->age_max ?? 200));
        }

        if ($preference->height_min_cm !== null || $preference->height_max_cm !== null) {
            $height = $candidate->height_cm;
            $checks[] = new PreferenceCheck(__('Height'), $this->heightRange($preference),
                $height !== null && $height >= ($preference->height_min_cm ?? 0) && $height <= ($preference->height_max_cm ?? 999));
        }

        $this->listCheck($checks, __('Marital status'), $preference->marital_statuses, $candidate->marital_status?->value,
            fn (string $v): string => MaritalStatus::tryFrom($v)?->label() ?? $v);
        $this->listCheck($checks, __('Physical status'), $preference->physical_statuses, $candidate->physical_status->value,
            fn (string $v): string => PhysicalStatus::tryFrom($v)?->label() ?? $v);
        $this->idCheck($checks, __('Religion'), $preference->religion_ids, $candidate->religion_id, $this->masters->religions());
        $this->idCheck($checks, __('Caste'), $preference->caste_ids, $candidate->caste_id, $this->castes($preference->religion_ids));
        $this->idCheck($checks, __('Mother tongue'), $preference->mother_tongue_ids, $candidate->mother_tongue_id, $this->masters->motherTongues());
        $this->idCheck($checks, __('Star'), $preference->star_ids, $candidate->star_id, $this->masters->stars());
        $this->idCheck($checks, __('Education'), $preference->education_ids, $career?->education_id, $this->masters->education());
        $this->idCheck($checks, __('Occupation'), $preference->occupation_ids, $career?->occupation_id, $this->masters->occupations());

        if ($preference->min_income_band_id !== null) {
            $checks[] = $this->incomeCheck($preference->min_income_band_id, $career?->income_band_id);
        }

        $this->idCheck($checks, __('Country'), $preference->country_ids, $career?->current_country_id, $this->masters->countries());
        $this->idCheck($checks, __('District'), $preference->district_ids, $candidate->district_id, $this->districts());
        $this->idCheck($checks, __('Diet'), $preference->diet_option_ids, $candidate->lifestyleDetail?->diet_option_id, $this->masters->options('diet'));

        return $checks;
    }

    /** True when every preference that was set is met (the contact filter, R-M03-2). */
    public function meetsAll(Profile $candidate, ?PartnerPreference $preference): bool
    {
        foreach ($this->check($candidate, $preference) as $check) {
            if (! $check->matches) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<PreferenceCheck>  $checks
     * @param  array<int, mixed>|null  $wanted
     */
    private function listCheck(array &$checks, string $label, ?array $wanted, ?string $actual, callable $labelOf): void
    {
        if ($wanted === null || $wanted === []) {
            return;
        }

        $values = array_map(strval(...), $wanted);
        $checks[] = new PreferenceCheck($label, implode(', ', array_map($labelOf, $values)), $actual !== null && in_array($actual, $values, true));
    }

    /**
     * @param  list<PreferenceCheck>  $checks
     * @param  array<int, mixed>|null  $wanted
     * @param  list<MasterItem>  $items
     */
    private function idCheck(array &$checks, string $label, ?array $wanted, ?int $actual, array $items): void
    {
        if ($wanted === null || $wanted === []) {
            return;
        }

        $ids = array_map(intval(...), $wanted);
        $labels = Masters::forSelect($items);
        $names = array_values(array_filter(array_map(fn (int $id): ?string => $labels[$id] ?? null, $ids)));

        $checks[] = new PreferenceCheck($label, $this->summarise($names), $actual !== null && in_array($actual, $ids, true));
    }

    private function incomeCheck(int $minBandId, ?int $actualBandId): PreferenceCheck
    {
        // Bands are listed in ascending income order: compare positions, not ids.
        $order = array_map(fn (MasterItem $band): int => $band->id, $this->masters->incomeBands());
        $minPosition = array_search($minBandId, $order, true);
        $actualPosition = $actualBandId !== null ? array_search($actualBandId, $order, true) : false;
        $labels = Masters::forSelect($this->masters->incomeBands());

        return new PreferenceCheck(__('Annual income'), __(':band or more', ['band' => $labels[$minBandId] ?? '—']),
            $minPosition !== false && $actualPosition !== false && $actualPosition >= $minPosition);
    }

    private function heightRange(PartnerPreference $preference): string
    {
        $min = $preference->height_min_cm !== null ? HeightCm::of($preference->height_min_cm)->label() : null;
        $max = $preference->height_max_cm !== null ? HeightCm::of($preference->height_max_cm)->label() : null;

        return match (true) {
            $min !== null && $max !== null => $min.' – '.$max,
            $min !== null => __(':height or taller', ['height' => $min]),
            default => __('up to :height', ['height' => $max]),
        };
    }

    /** @param  list<string>  $names */
    private function summarise(array $names): string
    {
        return count($names) > 4
            ? implode(', ', array_slice($names, 0, 4)).' '.__('+ :n more', ['n' => count($names) - 4])
            : implode(', ', $names);
    }

    /**
     * @param  array<int, mixed>|null  $religionIds
     * @return list<MasterItem>
     */
    private function castes(?array $religionIds): array
    {
        $castes = [];
        foreach ($religionIds ?? [] as $religionId) {
            array_push($castes, ...$this->masters->castesForReligion((int) $religionId));
        }

        return $castes;
    }

    /** @return list<MasterItem> */
    private function districts(): array
    {
        $districts = [];
        foreach ($this->masters->countries() as $country) {
            foreach ($this->masters->statesForCountry($country->id) as $state) {
                array_push($districts, ...$this->masters->districtsForState($state->id));
            }
        }

        return $districts;
    }
}
