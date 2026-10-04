<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use App\Models\Masters\Caste;
use App\Models\Masters\Country;
use App\Models\Masters\District;
use App\Models\Masters\Education;
use App\Models\Masters\IncomeBand;
use App\Models\Masters\MasterOption;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Occupation;
use App\Models\Masters\Rasi;
use App\Models\Masters\Religion;
use App\Models\Masters\Star;
use App\Models\Masters\State;

/**
 * The A11 registry: every master list an admin can edit, in one place. Keys are URL slugs
 * (/masters/{key}). Lists that are PHP enums with code behind them (marital / physical status,
 * dosham, reject reasons) stay enums; report reasons and icebreaker templates arrive with their
 * modules (owner decision 2026-10-02). The chat / text profanity word lists live here as option
 * groups and feed the A04 pre-flags.
 */
final class MasterLists
{
    /** Option groups (master_options.group) => [label, FK references, JSON references]. */
    private const OPTIONS = [
        'diet' => ['Diet', [['lifestyle_details', 'diet_option_id']], [['partner_preferences', 'diet_option_ids']]],
        'smoking' => ['Smoking', [['lifestyle_details', 'smoking_option_id']], []],
        'drinking' => ['Drinking', [['lifestyle_details', 'drinking_option_id']], []],
        'complexion' => ['Complexion', [['lifestyle_details', 'complexion_option_id']], []],
        'body_type' => ['Body type', [['lifestyle_details', 'body_type_option_id']], []],
        'family_type' => ['Family type', [['family_details', 'family_type_option_id']], []],
        'family_status' => ['Family status', [['family_details', 'family_status_option_id']], []],
        'family_values' => ['Family values', [['family_details', 'family_values_option_id']], []],
    ];

    /** Word lists for the A04 pre-flags: group => label. */
    public const WORD_LISTS = [
        'profanity_en' => 'Profanity — English',
        'profanity_ml' => 'Profanity — Malayalam',
        'profanity_manglish' => 'Profanity — Manglish',
    ];

    /** @var array<string, MasterList>|null */
    private static ?array $lists = null;

    /** @return array<string, MasterList> key => list, in sidebar order */
    public static function all(): array
    {
        if (self::$lists !== null) {
            return self::$lists;
        }

        $lists = [
            new MasterList('religions', 'Religions', 'Community', Religion::class,
                references: [['profiles', 'religion_id'], ['master_castes', 'religion_id']], jsonReferences: [['partner_preferences', 'religion_ids']]),
            new MasterList('castes', 'Castes', 'Community', Caste::class, parentKey: 'religions', parentColumn: 'religion_id',
                references: [['profiles', 'caste_id']], jsonReferences: [['partner_preferences', 'caste_ids']]),
            new MasterList('mother-tongues', 'Mother tongues', 'Community', MotherTongue::class,
                references: [['profiles', 'mother_tongue_id']], jsonReferences: [['partner_preferences', 'mother_tongue_ids']]),
            new MasterList('stars', 'Stars (nakshatra)', 'Horoscope', Star::class,
                references: [['profiles', 'star_id']], jsonReferences: [['partner_preferences', 'star_ids']]),
            new MasterList('rasis', 'Rasi', 'Horoscope', Rasi::class, references: [['profiles', 'rasi_id']]),
            new MasterList('countries', 'Countries', 'Location', Country::class,
                references: [['master_states', 'country_id'], ['education_careers', 'current_country_id'], ['education_careers', 'permanent_country_id'], ['contact_details', 'country_id']],
                jsonReferences: [['partner_preferences', 'country_ids']]),
            new MasterList('states', 'States', 'Location', State::class, parentKey: 'countries', parentColumn: 'country_id',
                references: [['master_districts', 'state_id'], ['education_careers', 'current_state_id'], ['education_careers', 'permanent_state_id'], ['contact_details', 'state_id']]),
            new MasterList('districts', 'Districts', 'Location', District::class, parentKey: 'states', parentColumn: 'state_id',
                references: [['profiles', 'district_id'], ['education_careers', 'current_district_id'], ['education_careers', 'permanent_district_id'], ['contact_details', 'district_id']],
                jsonReferences: [['partner_preferences', 'district_ids']]),
            new MasterList('education', 'Education', 'Career', Education::class,
                references: [['education_careers', 'education_id']], jsonReferences: [['partner_preferences', 'education_ids']]),
            new MasterList('occupations', 'Occupations', 'Career', Occupation::class,
                references: [['education_careers', 'occupation_id']], jsonReferences: [['partner_preferences', 'occupation_ids']]),
            // Labels / order / active only: a band's amount range (paise) isn't editable here, so new bands
            // can't be added in A11 (they'd have no range) — decisions 2026-10-03.
            new MasterList('income-bands', 'Income bands', 'Career', IncomeBand::class,
                references: [['education_careers', 'income_band_id'], ['partner_preferences', 'min_income_band_id']], allowsNewRows: false),
        ];

        foreach (self::OPTIONS as $group => [$label, $references, $jsonReferences]) {
            $lists[] = new MasterList('option-'.str_replace('_', '-', $group), $label, 'Lifestyle & family', MasterOption::class,
                group: $group, references: $references, jsonReferences: $jsonReferences);
        }

        foreach (self::WORD_LISTS as $group => $label) {
            $lists[] = new MasterList(str_replace('_', '-', $group), $label, 'Moderation', MasterOption::class, group: $group, isWordList: true);
        }

        return self::$lists = collect($lists)->keyBy('key')->all();
    }

    public static function find(string $key): ?MasterList
    {
        return self::all()[$key] ?? null;
    }
}
