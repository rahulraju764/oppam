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

// ---- P7.7 import pipeline helpers ---------------------------------------------------------------

/** Upload + validate now (the parse job run inline); the READY batch. */
function pipelineReady(App\Models\User $login, array $rows, ?UploadedFile $zip = null): App\Models\BrokerImportBatch
{
    $batch = app(App\Actions\Broker\Import\StoreImportBatch::class)->handle($login, importXlsx($rows), $zip);
    app()->call([new App\Jobs\Broker\ParseImportBatch($batch->id), 'handle']);

    return $batch->refresh();
}

/** The uploader attests and starts; the job is then run inline. */
function importNow(App\Models\User $login, App\Models\BrokerImportBatch $batch): App\Models\BrokerImportBatch
{
    app(App\Actions\Broker\Import\AttestBatchConsent::class)->handle($login, $batch->code);
    app(App\Actions\Broker\Import\StartBatchImport::class)->handle($login, $batch->code);

    return runImportJob($batch);
}

function runImportJob(App\Models\BrokerImportBatch $batch): App\Models\BrokerImportBatch
{
    app()->call([new App\Jobs\Broker\ImportBatchRows($batch->id), 'handle']);

    return $batch->refresh();
}

/** A setting changed for this test (the repository memoises values: flushed). */
function settingIs(App\Enums\SettingKey $key, int $value): void
{
    App\Models\Setting::query()->where('key', $key->value)->delete();
    App\Models\Setting::factory()->keyed($key, $value)->create();
    app(App\Services\Settings\SettingsRepository::class)->flush();
}

function rowAt(App\Models\BrokerImportBatch $batch, int $rowNumber): App\Models\BrokerImportRow
{
    return App\Models\BrokerImportRow::query()->where('batch_id', $batch->id)->where('row_number', $rowNumber)->firstOrFail();
}

/** Past the new-bureau cap (decisions 2026-10-28): 60 days since KYC, 20 moderated profiles, all approved. */
function establishedBureau(App\Models\User $login): App\Models\Broker
{
    $broker = staffOf($login)->broker;
    $broker->forceFill(['activated_at' => now()->subDays(60)])->save();
    App\Models\Profile::factory()->count(20)->brokerManaged($broker)->create()->each(fn (App\Models\Profile $p) => App\Models\ModerationItem::factory()->create([
        'type' => App\Enums\ModerationItemType::ProfileNew, 'profile_id' => $p->id, 'status' => App\Enums\ModerationStatus::Approved, 'decided_at' => now(),
    ]));

    return $broker->refresh();
}
