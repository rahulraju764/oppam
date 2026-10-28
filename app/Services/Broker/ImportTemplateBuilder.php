<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Data\Masters\MasterItem;
use App\Domain\Broker\Import\CellParser;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\Dosham;
use App\Enums\EmployerType;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\PhysicalStatus;
use App\Jobs\Broker\ParseImportBatch;
use App\Services\Masters\Masters;
use BackedEnum;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\NamedRange;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The bulk-import template (§11A B.9 "generated live from master data"): a *Profiles* sheet (the
 * ImportColumns headers in wizard order, frozen, ready for up to 500 rows, with drop-downs), one
 * reference sheet per list (code + name, read from the cached Masters, so an admin change in A11
 * shows up in the next download), a *Castes* sheet listing each religion's castes, and an
 * *Instructions* sheet (required columns, formats, limits, photos, an example row). The CSV variant
 * carries the header row only (UTF-8 with a BOM so Excel shows Malayalam).
 */
final class ImportTemplateBuilder
{
    private const ROWS = ParseImportBatch::MAX_ROWS + 1;

    public function __construct(private readonly Masters $masters) {}

    public function xlsx(): string
    {
        $book = new Spreadsheet;
        $profiles = $book->getActiveSheet();
        $profiles->setTitle('Profiles');
        $profiles->fromArray([ImportColumns::headers()], null, 'A1');
        $profiles->freezePane('A2');
        $profiles->getStyle('1:1')->getFont()->setBold(true);

        foreach (array_keys(ImportColumns::COLUMNS) as $i => $field) {
            $profiles->getColumnDimensionByColumn($i + 1)->setAutoSize(true);
            $list = $this->listFor($field);
            if ($list !== null) {
                $this->dropDown($book, $profiles, $i + 1, $field, $list);
            }
        }

        $castes = $book->createSheet()->setTitle('Castes');
        $rows = [['religion', 'caste_code', 'caste']];
        foreach ($this->masters->religions() as $religion) {
            foreach ($this->masters->castesForReligion($religion->id) as $caste) {
                $rows[] = array_map([CellParser::class, 'neutralise'], [$religion->label, $caste->code, $caste->label]);   // admin-typed labels: never a live formula (B.18)
            }
        }
        $castes->fromArray($rows, null, 'A1');

        $locations = $book->createSheet()->setTitle('Locations');
        $rows = [['country', 'state', 'district']];
        foreach ($this->masters->countries() as $country) {
            foreach ($this->masters->statesForCountry($country->id) as $state) {
                foreach ($this->masters->districtsForState($state->id) as $district) {
                    $rows[] = array_map([CellParser::class, 'neutralise'], [$country->label, $state->label, $district->label]);
                }
            }
        }
        $locations->fromArray($rows, null, 'A1');

        $this->instructions($book->createSheet()->setTitle('Instructions'));
        $book->setActiveSheetIndex(0);

        $out = fopen('php://temp', 'r+b');
        try {
            (new Xlsx($book))->save($out);
        } finally {
            $book->disconnectWorksheets();   // cyclic refs: free the workbook now, not at the end of the process
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    public function csv(): string
    {
        $out = fopen('php://temp', 'r+b');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ImportColumns::headers(), ',', '"', '');
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /** @return list<string>|null the drop-down values for a column (names; codes work too when typed) */
    private function listFor(string $field): ?array
    {
        $names = fn (array $items): array => array_map(fn (MasterItem $i): string => $i->label, $items);
        $enum = fn (string $enum): array => array_map(fn (BackedEnum $c): string => (string) (method_exists($c, 'label') ? $c->label() : $c->value), $enum::cases());

        return match ($field) {
            'gender' => $enum(Gender::class),
            'marital_status' => $enum(MaritalStatus::class),
            'physical_status' => $enum(PhysicalStatus::class),
            'chovva_dosham', 'papa_dosham' => $enum(Dosham::class),
            'employer_type' => $enum(EmployerType::class),
            'religion_id' => $names($this->masters->religions()),
            'mother_tongue_id' => $names($this->masters->motherTongues()),
            'star_id' => $names($this->masters->stars()),
            'rasi_id' => $names($this->masters->rasis()),
            'current_country_id' => $names($this->masters->countries()),
            'education_id' => $names($this->masters->education()),
            'occupation_id' => $names($this->masters->occupations()),
            'income_band_id' => $names($this->masters->incomeBands()),
            'caste_no_bar', 'client_consent' => ['YES', 'NO'],
            default => null,
        };
    }

    /** @param  list<string>  $values */
    private function dropDown(Spreadsheet $book, Worksheet $profiles, int $column, string $field, array $values): void
    {
        $name = 'list_'.preg_replace('/[^a-z_]/', '', $field);
        $ref = $book->getSheetByName('Lists') ?? $book->createSheet()->setTitle('Lists');
        $refColumn = $book->getNamedRanges() === [] ? 1 : count($book->getNamedRanges()) + 1;
        $letter = Coordinate::stringFromColumnIndex($refColumn);

        $ref->setCellValue($letter.'1', ImportColumns::header($field));
        foreach ($values as $i => $value) {
            $ref->setCellValueExplicit($letter.($i + 2), CellParser::neutralise($value), DataType::TYPE_STRING);
        }
        $book->addNamedRange(new NamedRange($name, $ref, '$'.$letter.'$2:$'.$letter.'$'.(count($values) + 1)));

        $target = Coordinate::stringFromColumnIndex($column);
        $validation = new DataValidation;
        $validation->setType(DataValidation::TYPE_LIST)
            ->setErrorStyle(DataValidation::STYLE_INFORMATION)   // codes typed by hand are accepted too
            ->setAllowBlank(true)
            ->setShowDropDown(true)
            ->setShowErrorMessage(true)
            ->setErrorTitle(__('Not in the list'))
            ->setError(__('Pick a value from the list, or type its code from the reference sheets.'))
            ->setFormula1($name);
        $profiles->setDataValidation($target.'2:'.$target.self::ROWS, $validation);
    }

    private function instructions(Worksheet $sheet): void
    {
        $required = array_map(fn (string $f): string => ImportColumns::header($f), ImportColumns::required());
        $lines = [
            [__('How to fill this template')],
            [''],
            [__('One row per client on the Profiles sheet. Keep the header row as it is.')],
            [__('Required columns: :columns.', ['columns' => implode(', ', $required)])],
            [__('client_consent must say YES: the client has agreed to this profile and to Oppam’s Terms & Privacy Policy.')],
            [__('Dates: DD-MM-YYYY, for example 12-05-1998. Height: in cm (163) or feet and inches (5\'4").')],
            [__('Lists: pick from the drop-down, or type the code or exact name from the reference sheets (Lists, Castes, Locations). The caste must belong to the religion; the district to the state.')],
            [__('country can be left empty for India.')],
            [__('photo_files: the photo file names in your zip, separated by ; (the first is the main photo). At most :max photos per client.', ['max' => 5])],
            [__('Limits: :rows rows per file; sheet up to 10 MB; zip up to 200 MB (JPG, PNG, WebP, HEIC, each up to 10 MB).', ['rows' => ParseImportBatch::MAX_ROWS])],
            [__('A mobile number already on Oppam, or used twice in the file, can’t be imported.')],
            [''],
            [__('Example row (do not copy it into Profiles):')],
            ImportColumns::headers(),
            ['Anjali', 'Nair', 'Female', '12-05-1998', '163', '55', 'Never married', '0', 'Normal', 'Hindu', '', 'NO', '', 'Malayalam',
                '', '', '', '', 'India', 'Kerala', 'Ernakulam', 'Kochi', '', '', 'Private', '', '', '', '9876512345', 'YES', 'anjali_1.jpg; anjali_2.jpg'],
        ];
        $sheet->fromArray($lines, null, 'A1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(110);
    }
}
