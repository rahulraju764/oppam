<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Data\Masters\MasterItem;
use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Services\Masters\Masters;
use App\ValueObjects\HeightCm;
use BackedEnum;
use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Turns one raw import cell into the value the wizard's rules expect (§11A B.9 "parsing edge
 * cases"; decisions 2026-10-26). Never trusts a cell: it only ever returns a value the validator
 * then checks with the single form's rules (ManagedProfileRules), or a parse error.
 *
 *  - master lists: a code or the exact label, case-insensitive → the master id (caste within the
 *    row's religion, state within its country, district within its state);
 *  - enums: the stored value or its label;
 *  - dates: Excel serial numbers, DD-MM-YYYY, DD/MM/YYYY, YYYY-MM-DD, YYYY/MM/DD; anything else
 *    (2-digit years, unknown formats) → "use DD-MM-YYYY";
 *  - height: 163 · 163 cm · 5'4" · 5 ft 4 in · 5.4 (feet.inches) → cm;
 *  - text: trimmed UTF-8 (Malayalam fine); a cell that a spreadsheet would run as a formula
 *    (= + - @, tab, CR) is never evaluated (raw reads); neutralise() prefixes an apostrophe wherever a
 *    value goes back into a spreadsheet (the preview's raw copy, errors.xlsx) — the value itself is kept.
 */
final class CellParser
{
    /** @var array<string, array<string, int>> list => lowercased code / label => id */
    private array $lookups = [];

    public function __construct(private readonly Masters $masters) {}

    /**
     * @param  array<string, mixed>  $row  the row's values parsed so far (religion, country, state)
     * @return array{0: mixed, 1: string|null} [value, parse error]
     */
    public function parse(string $kind, mixed $cell, array $row = []): array
    {
        $text = self::text($cell);

        if ($text === '' && ! in_array($kind, ['consent', 'bool'], true)) {
            return [null, null];
        }

        return match (true) {
            $kind === 'text' => [$text, null],   // the value as written; neutralise() is for what goes back into a spreadsheet
            $kind === 'int' => preg_match('/^\d{1,4}$/', $text) === 1 ? [(int) $text, null] : [null, __('Enter a whole number.')],
            $kind === 'date' => $this->date($cell, $text),
            $kind === 'height' => $this->height($text),
            $kind === 'bool' => $this->yesNo($text),
            $kind === 'consent' => [in_array(mb_strtoupper($text), ['YES', 'Y'], true), null],
            $kind === 'phone' => [$text, null],
            $kind === 'photos' => [self::photoNames($text), null],
            str_starts_with($kind, 'enum:') => $this->enum(substr($kind, 5), $text),
            str_starts_with($kind, 'master:') => $this->master(substr($kind, 7), $text, $row),
            default => [null, null],
        };
    }

    /** A cell as trimmed text (numbers without a trailing ".0"). */
    public static function text(mixed $cell): string
    {
        if ($cell === null || is_bool($cell)) {
            return '';
        }

        if (is_float($cell) && floor($cell) === $cell && abs($cell) < 1e15) {
            return (string) (int) $cell;
        }

        return trim(is_scalar($cell) ? (string) $cell : '');
    }

    /** CSV / formula injection: = + - @ (or a tab / CR) at the start → kept as text behind an apostrophe. */
    public static function neutralise(string $text): string
    {
        return $text !== '' && str_contains("=+-@\t\r", $text[0]) ? "'".$text : $text;
    }

    /** @return list<string> */
    public static function photoNames(string $text): array
    {
        $names = array_map(fn (string $n): string => trim($n), preg_split('/[;,]/', $text) ?: []);

        return array_values(array_filter($names, fn (string $n): bool => $n !== ''));
    }

    /** @return array{0: string|null, 1: string|null} */
    private function date(mixed $cell, string $text): array
    {
        $ask = __('Write the date as DD-MM-YYYY, for example 12-05-1998.');

        try {
            if (is_numeric($cell) && (float) $cell > 1000 && (float) $cell < 80000) {   // an Excel serial date
                return [CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $cell))->format('Y-m-d'), null];
            }
        } catch (Throwable) {
            return [null, $ask];
        }

        $patterns = [
            '/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/' => [3, 2, 1],   // DD-MM-YYYY
            '/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/' => [1, 2, 3],   // YYYY-MM-DD
        ];

        foreach ($patterns as $pattern => [$y, $m, $d]) {
            if (preg_match($pattern, $text, $p) === 1 && checkdate((int) $p[$m], (int) $p[$d], (int) $p[$y])) {
                return [sprintf('%04d-%02d-%02d', (int) $p[$y], (int) $p[$m], (int) $p[$d]), null];
            }
        }

        return [null, $ask];
    }

    /** @return array{0: int|null, 1: string|null} */
    private function height(string $text): array
    {
        $t = mb_strtolower(str_replace(['’', '′', '″', '”'], ["'", "'", '"', '"'], $text));
        $cm = match (true) {
            preg_match('/^(\d{2,3})(\s*cm)?$/', $t, $m) === 1 => (int) $m[1],
            preg_match('/^(\d)\s*(?:\'|ft|feet)\s*(\d{1,2})?\s*(?:"|in|inch|inches)?$/', $t, $m) === 1 => (int) round(((int) $m[1] * 12 + (int) ($m[2] ?? 0)) * 2.54),
            preg_match('/^(\d)\.(\d{1,2})$/', $t, $m) === 1 && (int) $m[2] < 12 => (int) round(((int) $m[1] * 12 + (int) $m[2]) * 2.54),
            default => null,
        };

        return $cm !== null && $cm >= HeightCm::MIN && $cm <= HeightCm::MAX
            ? [$cm, null]
            : [null, __('Write the height in cm (163) or feet and inches (5\'4").')];
    }

    /** @return array{0: bool|null, 1: string|null} */
    private function yesNo(string $text): array
    {
        return match (mb_strtoupper($text)) {
            'YES', 'Y', 'TRUE', '1' => [true, null],
            '', 'NO', 'N', 'FALSE', '0' => [false, null],
            default => [null, __('Write YES or NO.')],
        };
    }

    /** @return array{0: string|null, 1: string|null} */
    private function enum(string $name, string $text): array
    {
        /** @var class-string<BackedEnum> $enum */
        $enum = match ($name) {
            'gender' => Gender::class,
            'marital' => MaritalStatus::class,
            'physical' => PhysicalStatus::class,
            'employer' => EmployerType::class,
            default => Dosham::class,
        };

        $needle = mb_strtolower($text);
        foreach ($enum::cases() as $case) {
            $label = method_exists($case, 'label') ? (string) $case->label() : '';
            if (in_array($needle, [mb_strtolower((string) $case->value), mb_strtolower(str_replace('_', ' ', (string) $case->value)), mb_strtolower($label)], true)) {
                return [(string) $case->value, null];
            }
        }

        return [null, __('Use one of: :options.', ['options' => implode(', ', array_map(
            fn (BackedEnum $c): string => method_exists($c, 'label') ? (string) $c->label() : (string) $c->value, $enum::cases()))])];
    }

    /**
     * @param  array<string, mixed>  $row  values parsed so far (religion, country, state)
     * @return array{0: int|null, 1: string|null}
     */
    private function master(string $list, string $text, array $row): array
    {
        // One lookup per list (and per religion / country / state): the master lists are read once
        // per batch, not once per cell.
        $key = $list.':'.match ($list) {
            'castes' => $row['religion_id'] ?? '',
            'states' => $row['current_country_id'] ?? '',
            'districts' => $row['current_state_id'] ?? '',
            default => '',
        };
        $this->lookups[$key] ??= self::index($this->items($list, $row));

        $id = $this->lookups[$key][mb_strtolower($text)] ?? null;
        if ($id !== null) {
            return [$id, null];
        }

        $names = array_slice(array_map(fn (MasterItem $i): string => $i->label, $this->items($list, $row)), 0, 12);

        return [null, match ($list) {
            'castes' => $names === []
                ? __('Choose the religion first; the caste must belong to it.')
                : __('This caste is not listed for the religion. Valid options include: :options.', ['options' => implode(', ', $names)]),
            'states', 'districts' => __('Not a known :what for the chosen location. Check the reference sheet.', ['what' => $list === 'states' ? __('state') : __('district')]),
            default => __('Not a known value. Use a code or the exact name from the reference sheet.'),
        }];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<MasterItem>
     */
    private function items(string $list, array $row): array
    {
        return match ($list) {
            'religions' => $this->masters->religions(),
            'castes' => isset($row['religion_id']) ? $this->masters->castesForReligion((int) $row['religion_id']) : [],
            'mother_tongues' => $this->masters->motherTongues(),
            'stars' => $this->masters->stars(),
            'rasis' => $this->masters->rasis(),
            'countries' => $this->masters->countries(),
            'states' => isset($row['current_country_id']) ? $this->masters->statesForCountry((int) $row['current_country_id']) : [],
            'districts' => isset($row['current_state_id']) ? $this->masters->districtsForState((int) $row['current_state_id']) : [],
            'education' => $this->masters->education(),
            'occupations' => $this->masters->occupations(),
            'income_bands' => $this->masters->incomeBands(),
            default => [],
        };
    }

    /**
     * @param  list<MasterItem>  $items
     * @return array<string, int>
     */
    private static function index(array $items): array
    {
        $index = [];
        foreach ($items as $item) {
            $index[mb_strtolower($item->code)] ??= $item->id;
            $index[mb_strtolower($item->label)] ??= $item->id;
        }

        return $index;
    }
}
