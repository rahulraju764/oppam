<?php

declare(strict_types=1);

use App\Actions\Broker\Import\SaveColumnMapping;
use App\Actions\Broker\Import\StoreImportBatch;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Events\Broker\ImportProgress;
use App\Jobs\Broker\ParseImportBatch;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\Profile;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/*
| P7.6 — ParseImportBatch, step 2 of a bulk import (§11A B.9 "auto-map headers, validate each row
| with the SAME rule set, duplicates, photo names, progress broadcast"; parsing edge cases): runs
| on the `imports` queue in chunks of 100 with live ImportProgress; MAPPING when required columns
| are missing; 500 rows max; idempotent; ≤ 30 s for 500 rows (decisions 2026-10-26).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    Storage::fake((string) config('oppam.media.private_disk'));
    Queue::fake();
});

/** Upload and run the validation job now; returns the fresh batch. */
function parsed(App\Models\User $login, UploadedFile $sheet, ?UploadedFile $zip = null): BrokerImportBatch
{
    $batch = app(StoreImportBatch::class)->handle($login, $sheet, $zip);
    app()->call([new ParseImportBatch($batch->id), 'handle']);

    return $batch->refresh();
}

function rowOf(BrokerImportBatch $batch, int $rowNumber = 2): BrokerImportRow
{
    return BrokerImportRow::query()->where('batch_id', $batch->id)->where('row_number', $rowNumber)->firstOrFail();
}

/** @return list<string> the row's messages as "field: text" */
function messagesOf(BrokerImportRow $row): array
{
    return array_map(fn (array $m): string => $m['field'].': '.$m['text'], $row->messages ?? []);
}

it('B.9 step 2: valid rows → READY with counts; every row stored with its sheet row number; nothing becomes a profile', function (): void {
    $batch = parsed(bureauLogin(), importXlsx([importRow(), importRow(['first_name' => 'Meera'], '9876500102')]));

    expect($batch->status)->toBe(ImportBatchStatus::Ready)
        ->and($batch->rows_total)->toBe(2)
        ->and($batch->rows_valid)->toBe(2)
        ->and($batch->rows_error)->toBe(0)
        ->and(rowOf($batch, 2)->status)->toBe(ImportRowStatus::Valid)
        ->and(rowOf($batch, 3)->data['raw']['first_name'])->toBe('Meera')
        ->and(Profile::query()->count())->toBe(0);
});

it('B.15: progress is broadcast after each chunk of 100 rows and at READY — codes and counts only', function (): void {
    Event::fake([ImportProgress::class]);
    $login = bureauLogin();

    $batch = parsed($login, importXlsx(importRows(250)));

    Event::assertDispatchedTimes(ImportProgress::class, 4);   // 100, 200, 250, READY
    Event::assertDispatched(ImportProgress::class, fn (ImportProgress $e): bool => $e->status === 'READY'
        && $e->broadcastWith() === ['batch' => $batch->code, 'status' => 'READY', 'processed' => 250, 'total' => 250, 'valid' => 250, 'warning' => 0, 'error' => 0]
        && $e->broadcastOn()[0]->name === 'private-broker.'.staffOf($login)->broker_id
        && $e->broadcastAs() === 'import.progress');
});

it('★ Done-when: a 500-row file validates within the 30 s budget', function (): void {
    $sheet = importXlsx(importRows(500));
    $started = microtime(true);

    $batch = parsed(bureauLogin(), $sheet);
    $elapsed = microtime(true) - $started;
    fwrite(STDERR, sprintf("\n[P7.6] 500 rows validated in %.1f s\n", $elapsed));

    expect($batch->status)->toBe(ImportBatchStatus::Ready)
        ->and($batch->rows_valid)->toBe(500)
        ->and($elapsed)->toBeLessThan(30.0);
});

it('B.9 limits: more than 500 rows → FAILED with the reason; an empty sheet → FAILED', function (): void {
    $over = parsed(bureauLogin(), importXlsx(importRows(501)));
    expect($over->status)->toBe(ImportBatchStatus::Failed)
        ->and($over->error_message)->toContain('at most 500 rows')
        ->and(BrokerImportRow::query()->where('batch_id', $over->id)->count())->toBe(0);

    $empty = parsed(bureauLogin(), importXlsx([]));
    expect($empty->status)->toBe(ImportBatchStatus::Failed);
});

it('headers are auto-mapped from aliases and any order; missing required columns → MAPPING, then the bureau maps them', function (): void {
    $login = bureauLogin();
    $row = importRow();
    // Aliases and shuffled order: "Name", "Surname", "DOB", "Mobile" …
    $renamed = ['Name' => $row['first_name'], 'Surname' => $row['last_name'], 'Sex' => $row['gender'], 'DOB' => $row['date_of_birth'],
        'Height (cm)' => $row['height'], 'Marital' => $row['marital_status'], 'Religion' => $row['religion'], 'Language' => $row['mother_tongue'],
        'State' => $row['state'], 'Location' => $row['district'], 'Mobile' => $row['client_mobile'], 'Consent' => $row['client_consent']];
    $auto = parsed($login, importXlsx([$renamed], array_keys($renamed)));
    expect($auto->status)->toBe(ImportBatchStatus::Ready)->and($auto->rows_valid)->toBe(1);

    // Headers no alias knows → MAPPING.
    $odd = ['Kutty name' => $row['first_name']] + array_diff_key($renamed, ['Name' => true]);
    $batch = parsed($login, importXlsx([$odd], array_keys($odd)));
    expect($batch->status)->toBe(ImportBatchStatus::Mapping)->and($batch->headers)->toContain('Kutty name');

    expect(fn () => app(SaveColumnMapping::class)->handle($login, $batch->code, ['first_name' => 'Not a header']))->toThrow(ValidationException::class);
    expect(fn () => app(SaveColumnMapping::class)->handle($login, $batch->code, ['last_name' => 'Surname']))->toThrow(ValidationException::class);   // first_name still missing

    app(SaveColumnMapping::class)->handle($login, $batch->code, [...$batch->column_map, 'first_name' => 'Kutty name']);
    Queue::assertPushed(ParseImportBatch::class, fn (ParseImportBatch $j): bool => $j->batchId === $batch->id);
    app()->call([new ParseImportBatch($batch->id), 'handle']);

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready)->and($batch->rows_valid)->toBe(1);
});

it('B.9 parsing edge cases: dates, heights, codes or labels', function (array $cells, string $field, mixed $expected): void {
    $batch = parsed(bureauLogin(), importXlsx([importRow($cells)]));
    $row = rowOf($batch);
    $expected = $expected instanceof Closure ? $expected() : $expected;

    expect($row->status)->toBe(ImportRowStatus::Valid, implode(' | ', messagesOf($row)))
        ->and($row->data['values'][$field])->toBe($expected);
})->with([
    'DD-MM-YYYY' => [['date_of_birth' => '12-05-1998'], 'dob', '1998-05-12'],
    'DD/MM/YYYY' => [['date_of_birth' => '12/05/1998'], 'dob', '1998-05-12'],
    'YYYY-MM-DD' => [['date_of_birth' => '1998-05-12'], 'dob', '1998-05-12'],
    'YYYY/MM/DD' => [['date_of_birth' => '1998/05/12'], 'dob', '1998-05-12'],
    'Excel serial date' => [['date_of_birth' => 35927], 'dob', '1998-05-12'],
    'height cm' => [['height' => '163 cm'], 'height_cm', 163],
    'height feet inches' => [['height' => "5'4\""], 'height_cm', 163],
    'height ft in words' => [['height' => '5 ft 4 in'], 'height_cm', 163],
    'height 5.4' => [['height' => '5.4'], 'height_cm', 163],
    'gender as value' => [['gender' => 'FEMALE'], 'gender', 'FEMALE'],
    'marital as value' => [['marital_status' => 'never_married'], 'marital_status', 'NEVER_MARRIED'],
    'religion by label' => [['religion' => 'hindu'], 'religion_id', fn () => masterId(App\Models\Masters\Religion::class, 'HINDU')],
    'Malayalam text' => [['about' => 'ഞാൻ കൊച്ചിയിൽ ജോലി ചെയ്യുന്നു. സംഗീതവും യാത്രയും ഇഷ്ടമാണ്. കുടുംബത്തോടൊപ്പം സമയം ചെലവഴിക്കാൻ ഇഷ്ടം.'], 'about', 'ഞാൻ കൊച്ചിയിൽ ജോലി ചെയ്യുന്നു. സംഗീതവും യാത്രയും ഇഷ്ടമാണ്. കുടുംബത്തോടൊപ്പം സമയം ചെലവഴിക്കാൻ ഇഷ്ടം.'],
]);

it('B.9 parsing edge cases that are ERRORs, with a helpful message', function (array $cells, string $header, string $text): void {
    $batch = parsed(bureauLogin(), importXlsx([importRow($cells)]));
    $row = rowOf($batch);

    expect($row->status)->toBe(ImportRowStatus::Error)
        ->and(implode(' | ', messagesOf($row)))->toContain($header.': ')->toContain($text);
})->with([
    'ambiguous 2-digit year' => [['date_of_birth' => '12-05-98'], 'date_of_birth', 'DD-MM-YYYY'],
    'not a date' => [['date_of_birth' => 'May 12'], 'date_of_birth', 'DD-MM-YYYY'],
    'impossible date' => [['date_of_birth' => '31-02-1998'], 'date_of_birth', 'DD-MM-YYYY'],
    'height nonsense' => [['height' => 'tall'], 'height', 'cm'],
    'caste of another religion lists the valid ones' => [fn () => ['caste' => (string) App\Models\Masters\Caste::query()
        ->where('religion_id', masterId(App\Models\Masters\Religion::class, 'CHRISTIAN'))->value('code')], 'caste', 'Valid options include'],
    'unknown religion' => [['religion' => 'Martian'], 'religion', 'Not a known value'],
    'unknown gender' => [['gender' => 'X'], 'gender', 'Use one of'],
    'consent not YES' => [['client_consent' => 'NO'], 'client_consent', 'YES'],
    'consent missing' => [['client_consent' => ''], 'client_consent', 'YES'],
    'formula as a name' => [['first_name' => '=HYPERLINK("http://evil","x")'], 'first_name', 'first name'],
]);

it('B.18: formula-like cells are never evaluated — stored as text behind an apostrophe', function (): void {
    // A real formula cell in the workbook: read as its text, never calculated.
    $batch = parsed(bureauLogin(), importXlsx([importRow(['about' => '=1+1', 'city' => '@SUM(A1:A2)'])]));
    $row = rowOf($batch);

    // The preview's raw copy is neutralised (it goes back into errors.xlsx); the value itself is kept as written.
    expect($row->data['raw']['about'])->toBe("'=1+1")
        ->and($row->data['values']['about'])->toBe('=1+1')
        ->and($row->data['raw']['city'])->toBe("'@SUM(A1:A2)");
});

it('★ R-M13-29 duplicates: a mobile already on Oppam or repeated in the file → ERROR; same name + DOB in the bureau → WARNING', function (): void {
    $login = bureauLogin();
    $broker = staffOf($login)->broker;
    memberWithPhone('+919876500201');                                                          // a member
    managedProfile(App\Models\Broker::factory()->create(), ['client_phone' => '+919876500202']); // another bureau's client
    app(App\Domain\Safety\BanList::class)->add(App\Enums\BannedIdentifierType::Phone, '+919876500203', null, null, null);
    $dob = now('Asia/Kolkata')->subYears(30);
    managedProfile($broker, ['first_name' => 'Lakshmi', 'last_name' => 'Pillai', 'dob' => $dob->format('Y-m-d')]);

    $batch = parsed($login, importXlsx([
        importRow([], '9876500201'),
        importRow([], '9876500202'),
        importRow([], '9876500203'),
        importRow(['first_name' => 'Twin'], '9876500204'),
        importRow(['first_name' => 'Twin'], '9876500204'),
        importRow(['first_name' => 'Lakshmi', 'last_name' => 'Pillai', 'date_of_birth' => $dob->format('d-m-Y')], '9876500205'),
    ]));

    foreach ([2, 3, 4] as $r) {
        expect(rowOf($batch, $r)->status)->toBe(ImportRowStatus::Error)
            ->and(implode(' ', messagesOf(rowOf($batch, $r))))->toContain('already on Oppam');
    }
    foreach ([5, 6] as $r) {
        expect(implode(' ', messagesOf(rowOf($batch, $r))))->toContain('more than once in the file');
    }
    expect(rowOf($batch, 7)->status)->toBe(ImportRowStatus::Warning)
        ->and(implode(' ', messagesOf(rowOf($batch, 7))))->toContain('same name and date of birth')
        ->and($batch->rows_error)->toBe(5)->and($batch->rows_warning)->toBe(1);
});

it('B.9: photo names missing from the zip (or with no zip) → WARNING; names found → VALID', function (): void {
    $login = bureauLogin();
    $zip = importZip(['photos/anjali_1.jpg' => 'x', 'ANJALI_2.JPG' => 'y']);

    $batch = parsed($login, importXlsx([
        importRow(['photo_files' => 'anjali_1.jpg; anjali_2.jpg'], '9876500301'),
        importRow(['photo_files' => 'anjali_1.jpg; missing.jpg'], '9876500302'),
    ]), $zip);

    expect(rowOf($batch, 2)->status)->toBe(ImportRowStatus::Valid)
        ->and(rowOf($batch, 3)->status)->toBe(ImportRowStatus::Warning)
        ->and(implode(' ', messagesOf(rowOf($batch, 3))))->toContain('missing.jpg');

    $noZip = parsed($login, importXlsx([importRow(['photo_files' => 'a.jpg'], '9876500303')]));
    expect(rowOf($noZip)->status)->toBe(ImportRowStatus::Warning);
});

it('re-running the job rebuilds the rows (no duplicates); a batch that moved on is left alone', function (): void {
    $batch = parsed(bureauLogin(), importXlsx([importRow()]));
    $batch->forceFill(['status' => ImportBatchStatus::Validating])->save();

    app()->call([new ParseImportBatch($batch->id), 'handle']);

    expect(BrokerImportRow::query()->where('batch_id', $batch->id)->count())->toBe(1);

    $batch->refresh()->forceFill(['status' => ImportBatchStatus::Cancelled])->save();
    app()->call([new ParseImportBatch($batch->id), 'handle']);
    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Cancelled);
});

it('B.9: the uploader deactivated or KYC revoked mid-way — the job still completes; nothing is created', function (): void {
    $owner = bureauLogin();
    $entry = bureauLogin(BureauRole::DataEntry, broker: staffOf($owner)->broker);
    $batch = app(StoreImportBatch::class)->handle($entry, importXlsx([importRow()]), null);
    staffOf($entry)->forceFill(['status' => App\Enums\BureauStaffStatus::Deactivated])->save();
    staffOf($owner)->broker->forceFill(['kyc_status' => App\Enums\BrokerKycStatus::Rejected])->save();

    app()->call([new ParseImportBatch($batch->id), 'handle']);

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready)->and(Profile::query()->count())->toBe(0);
});

it('an unreadable file ends FAILED with a generic message (no cell contents)', function (): void {
    $batch = app(StoreImportBatch::class)->handle(bureauLogin(), importXlsx([importRow()]), null);
    Storage::disk((string) config('oppam.media.private_disk'))->put($batch->sheet_path, 'garbage');

    app()->call([new ParseImportBatch($batch->id), 'handle']);

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->error_message)->toBe('We could not read this file. Download the template and try again.');
});

it('B.18: a CSV formula cell is read as text too', function (): void {
    $batch = parsed(bureauLogin(), importCsv([importRow(['city' => '=HYPERLINK("http://x","y")'])]));

    expect(rowOf($batch)->data['raw']['city'])->toBe("'=HYPERLINK(\"http://x\",\"y\")");
});

it('★ SaveColumnMapping security matrix: another bureau’s batch → 404; a batch not waiting for a mapping → refused; one column for two fields → 422', function (): void {
    $login = bureauLogin();
    $row = importRow();
    $odd = ['Kutty name' => $row['first_name']] + array_diff_key($row, ['first_name' => true]);
    $mine = parsed($login, importXlsx([$odd], array_keys($odd)));
    $theirs = parsed(bureauLogin(), importXlsx([$odd], array_keys($odd)));
    $ready = parsed($login, importXlsx([importRow([], '9876500777')]));
    $map = [...$mine->column_map, 'first_name' => 'Kutty name'];

    expect(fn () => app(SaveColumnMapping::class)->handle($login, $theirs->code, $map))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class)
        ->and(fn () => app(SaveColumnMapping::class)->handle($login, $ready->code, $map))->toThrow(App\Exceptions\Broker\ImportRefused::class)
        ->and(fn () => app(SaveColumnMapping::class)->handle($login, $mine->code, [...$map, 'last_name' => 'Kutty name']))->toThrow(ValidationException::class);

    expect($theirs->refresh()->status)->toBe(ImportBatchStatus::Mapping)->and($mine->refresh()->status)->toBe(ImportBatchStatus::Mapping);
});

it('SaveColumnMapping respects the flag and a pause (R-A07-9)', function (): void {
    $login = bureauLogin();
    $row = importRow();
    $odd = ['Kutty name' => $row['first_name']] + array_diff_key($row, ['first_name' => true]);
    $batch = parsed($login, importXlsx([$odd], array_keys($odd)));
    staffOf($login)->broker->forceFill(['imports_paused_at' => now()])->save();
    app(App\Domain\Broker\BureauGate::class)->forget();   // a new request reads the bureau afresh

    expect(fn () => app(SaveColumnMapping::class)->handle($login, $batch->code, [...$batch->column_map, 'first_name' => 'Kutty name']))
        ->toThrow(App\Exceptions\Broker\ImportRefused::class, 'paused');
});

it('B.9: an oversized sheet is refused BEFORE its cells are loaded (dimension check)', function (): void {
    $batch = parsed(bureauLogin(), importXlsx(importRows(1100)));

    expect($batch->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->error_message)->toContain('more than 500 rows')
        ->and(BrokerImportRow::query()->where('batch_id', $batch->id)->count())->toBe(0);
});

it('two columns with the same name → FAILED with a clear message (no ambiguous reading)', function (): void {
    $headers = [...ImportColumns::headers(), 'first_name'];
    $batch = parsed(bureauLogin(), importXlsx([importRow()], $headers));

    expect($batch->status)->toBe(ImportBatchStatus::Failed)->and($batch->error_message)->toContain('Two columns are both called');
});

it('a worker that dies (memory, timeout) still ends the batch FAILED — failed() hook', function (): void {
    $batch = app(StoreImportBatch::class)->handle(bureauLogin(), importXlsx([importRow()]), null);
    $batch->forceFill(['status' => ImportBatchStatus::Validating])->save();

    (new ParseImportBatch($batch->id))->failed(new RuntimeException('Allowed memory size exhausted'));

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Failed)
        ->and($batch->error_message)->toBe(ParseImportBatch::genericFailure());
});

it('the decrypted temp copies are removed after parsing', function (): void {
    $before = glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oppam-import-*') ?: [];

    parsed(bureauLogin(), importXlsx([importRow(['photo_files' => 'a.jpg'])]), importZip(['a.jpg' => 'x']));

    expect(array_diff(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'oppam-import-*') ?: [], $before))->toBe([]);
});

it('the template’s own headers map one-to-one', function (): void {
    expect(ImportColumns::missing(ImportColumns::autoMap(ImportColumns::headers())))->toBe([])
        ->and(count(ImportColumns::autoMap(ImportColumns::headers())))->toBe(count(ImportColumns::COLUMNS));
});
