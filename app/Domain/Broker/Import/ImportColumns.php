<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use Illuminate\Support\Str;

/**
 * The bulk-import sheet's columns (§11A B.9; decisions 2026-10-26 "core sections"), in wizard
 * order: step-1 basics, the required current location, education & career, about, then the
 * client mobile, consent and photo file names. One list drives the template (headers, reference
 * lists, instructions), the header auto-mapping and the cell parser.
 *
 * kind: text | int | date | height | enum:<Enum> | master:<list> | bool | phone | consent | photos
 */
final class ImportColumns
{
    /**
     * field => [header, required, kind, aliases]. The field names are the wizard's own (ProfileRules
     * keys); client_mobile / client_consent map to the form's clientPhone / consent.
     *
     * @var array<string, array{0: string, 1: bool, 2: string, 3: list<string>}>
     */
    public const COLUMNS = [
        'first_name' => ['first_name', true, 'text', ['first', 'firstname', 'given_name', 'name']],
        'last_name' => ['last_name', true, 'text', ['last', 'lastname', 'surname', 'family_name']],
        'gender' => ['gender', true, 'enum:gender', ['sex']],
        'dob' => ['date_of_birth', true, 'date', ['dob', 'birth_date', 'birthdate', 'date_of_birth_dd_mm_yyyy']],
        'height_cm' => ['height', true, 'height', ['height_cm', 'height_in_cm']],
        'weight_kg' => ['weight_kg', false, 'int', ['weight']],
        'marital_status' => ['marital_status', true, 'enum:marital', ['marital']],
        'children_count' => ['children', false, 'int', ['children_count', 'no_of_children', 'number_of_children']],
        'physical_status' => ['physical_status', false, 'enum:physical', ['physical']],
        'religion_id' => ['religion', true, 'master:religions', []],
        'caste_id' => ['caste', false, 'master:castes', ['community']],
        'caste_no_bar' => ['caste_no_bar', false, 'bool', []],
        'sub_caste' => ['sub_caste', false, 'text', ['subcaste']],
        'mother_tongue_id' => ['mother_tongue', true, 'master:mother_tongues', ['language', 'mothertongue']],
        'star_id' => ['star', false, 'master:stars', ['nakshatra', 'birth_star']],
        'rasi_id' => ['rasi', false, 'master:rasis', ['raasi', 'moon_sign']],
        'chovva_dosham' => ['chovva_dosham', false, 'enum:dosham', ['chovva']],
        'papa_dosham' => ['papa_dosham', false, 'enum:dosham', ['papa']],
        'current_country_id' => ['country', false, 'master:countries', ['current_country']],
        'current_state_id' => ['state', true, 'master:states', ['current_state']],
        'current_district_id' => ['district', true, 'master:districts', ['current_district', 'location']],
        'current_city' => ['city', false, 'text', ['current_city', 'town', 'place']],
        'education_id' => ['education', false, 'master:education', ['qualification']],
        'education_detail' => ['education_detail', false, 'text', ['college', 'education_details']],
        'employer_type' => ['employed_in', false, 'enum:employer', ['employer_type', 'sector']],
        'occupation_id' => ['occupation', false, 'master:occupations', ['job', 'profession']],
        'income_band_id' => ['annual_income', false, 'master:income_bands', ['income', 'income_band']],
        'about' => ['about', false, 'text', ['about_me', 'description']],
        'client_mobile' => ['client_mobile', true, 'phone', ['mobile', 'phone', 'mobile_number', 'client_phone']],
        'client_consent' => ['client_consent', true, 'consent', ['consent']],
        'photo_files' => ['photo_files', false, 'photos', ['photos', 'photo']],
    ];

    /** @return list<string> the fields a sheet must have a column for */
    public static function required(): array
    {
        return array_keys(array_filter(self::COLUMNS, fn (array $c): bool => $c[1]));
    }

    /** @return list<string> the template's header row, in order */
    public static function headers(): array
    {
        return array_values(array_map(fn (array $c): string => $c[0], self::COLUMNS));
    }

    public static function header(string $field): string
    {
        return self::COLUMNS[$field][0];
    }

    /** `Date of Birth (DD-MM-YYYY)` → `date_of_birth_dd_mm_yyyy`. */
    public static function normalise(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower(trim($header))), '_');
    }

    /**
     * Map the sheet's headers to fields: the template's own header first, then an alias; each
     * header used once.
     *
     * @param  list<string>  $headers
     * @return array<string, string> field => header (as written in the sheet)
     */
    public static function autoMap(array $headers): array
    {
        $byNormalised = [];
        foreach ($headers as $header) {
            $key = self::normalise($header);
            if ($key !== '' && ! isset($byNormalised[$key])) {
                $byNormalised[$key] = $header;
            }
        }

        $map = [];
        foreach ([true, false] as $exactPass) {
            foreach (self::COLUMNS as $field => [$name, , , $aliases]) {
                if (isset($map[$field])) {
                    continue;
                }
                foreach ($exactPass ? [$name] : $aliases as $candidate) {
                    if (isset($byNormalised[$candidate]) && ! in_array($byNormalised[$candidate], $map, true)) {
                        $map[$field] = $byNormalised[$candidate];
                        break;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Why these headers can't be read safely, or null: two columns with the same name would make it
     * ambiguous which one holds the value. (Columns without a header are ignored.)
     *
     * @param  list<string>  $headers
     */
    public static function headerProblem(array $headers): ?string
    {
        $seen = [];
        foreach ($headers as $header) {
            $key = self::normalise($header);
            if ($key === '') {
                continue;
            }
            if (isset($seen[$key])) {
                return __('Two columns are both called “:name”. Rename or remove one and upload again.', ['name' => Str::limit($header, 60)]);   // error_message is varchar(300)
            }
            $seen[$key] = true;
        }

        return $seen === [] ? __('The first row must hold the column names. Download the template and try again.') : null;
    }

    /**
     * @param  array<string, string>  $map
     * @return list<string> required fields with no column
     */
    public static function missing(array $map): array
    {
        return array_values(array_diff(self::required(), array_keys($map)));
    }
}
