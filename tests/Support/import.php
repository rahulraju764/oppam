<?php

declare(strict_types=1);

use App\Domain\Broker\Import\ImportColumns;
use App\Models\Masters\Caste;
use App\Models\Masters\District;
use App\Models\Masters\MotherTongue;
use App\Models\Masters\Religion;
use App\Models\Masters\State;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
| P7.6 bulk-import test helpers: a valid import row (template headers => cells, as a bureau would
| type them: codes / labels, DD-MM-YYYY), and real .xlsx / .csv / .zip uploads built on the fly.
*/

/** One valid row keyed by the template headers; $overrides are header => cell. */
function importRow(array $overrides = [], string $mobile = '9876500101'): array
{
    $hindu = Religion::query()->where('code', 'HINDU')->firstOrFail();
    $kerala = State::query()->where('code', 'KL')->firstOrFail();

    return array_merge([
        'first_name' => 'Anjali',
        'last_name' => 'Nair',
        'gender' => 'Female',
        'date_of_birth' => now(config('oppam.display_timezone'))->subYears(26)->format('d-m-Y'),
        'height' => '163',
        'marital_status' => 'Never married',
        'religion' => $hindu->code,
        'caste' => (string) Caste::query()->where('religion_id', $hindu->id)->value('code'),
        'mother_tongue' => MotherTongue::query()->where('code', 'MALAYALAM')->value('label'),
        'state' => $kerala->code,
        'district' => (string) District::query()->whereKey(keralaDistrictId())->value('code'),
        'client_mobile' => $mobile,
        'client_consent' => 'YES',
    ], $overrides);
}

/** Header-keyed rows → a real .xlsx upload (headers = the template's unless given). */
function importXlsx(array $rows, ?array $headers = null, string $name = 'clients.xlsx'): UploadedFile
{
    $headers ??= ImportColumns::headers();
    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet()->setTitle('Profiles');
    $grid = [$headers];
    foreach ($rows as $row) {
        $grid[] = array_map(fn (string $h): mixed => $row[$h] ?? null, $headers);
    }
    $sheet->fromArray($grid, null, 'A1', true);

    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    (new Xlsx($book))->save($path);
    $book->disconnectWorksheets();

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** Header-keyed rows → a UTF-8 .csv upload (with a BOM, as Excel writes it). */
function importCsv(array $rows, ?array $headers = null): UploadedFile
{
    $headers ??= ImportColumns::headers();
    $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
    $out = fopen($path, 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $headers, ',', '"', '');
    foreach ($rows as $row) {
        fputcsv($out, array_map(fn (string $h): string => (string) ($row[$h] ?? ''), $headers), ',', '"', '');
    }
    fclose($out);

    return new UploadedFile($path, 'clients.csv', 'text/csv', null, true);
}

/** A .zip upload holding $entries (name => bytes). */
function importZip(array $entries, string $name = 'photos.zip'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $entry => $bytes) {
        $zip->addFromString($entry, $bytes);
    }
    $zip->close();

    return new UploadedFile($path, $name, 'application/zip', null, true);
}

/** Rows with distinct mobiles 98765 0xxxx. */
function importRows(int $count): array
{
    return array_map(fn (int $i): array => importRow(['first_name' => 'Client'.chr(65 + $i % 26).'a'], '98765'.str_pad((string) (1000 + $i), 5, '0', STR_PAD_LEFT)), range(1, $count));
}
