<?php

declare(strict_types=1);

use App\Actions\Broker\Import\FixImportRow;
use App\Actions\Broker\Import\ReuploadImportErrors;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportColumns;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Models\AuditLog;
use Database\Seeders\PlansSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.7 — §11A B.9 step 3 "fix cells inline, or download errors.xlsx, fix, and re-upload just those
| rows": FixImportRow and ReuploadImportErrors re-check rows with the SAME rules as the parse
| (R-M13-28) — duplicates across the batch included — in the caller's bureau only.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    fakeMediaDisks();
    Queue::fake();
});

it('B.9 step 3 / R-M13-28: an inline fix re-checks the row — ERROR becomes VALID, counts follow, audited without the value', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);
    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Error);

    $row = app(FixImportRow::class)->handle($login, $batch->code, 2, 'height', "5'4\"");

    expect($row->status)->toBe(ImportRowStatus::Valid)
        ->and($row->data['values']['height_cm'])->toBe(163)
        ->and([$batch->refresh()->rows_valid, $batch->rows_error])->toBe([1, 0]);
    $log = AuditLog::query()->where('action', 'broker.import_row_fixed')->firstOrFail();
    expect($log->after)->toBe(['row' => 2, 'column' => 'height']);
});

it('B.9 step 3: changing a repeated mobile re-checks its twin too', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow([], '9876500101'), importRow(['first_name' => 'Meera'], '9876500101')]);
    expect($batch->rows_error)->toBe(2);

    app(FixImportRow::class)->handle($login, $batch->code, 3, 'client_mobile', '9876500102');

    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Valid)
        ->and(rowAt($batch, 3)->status)->toBe(ImportRowStatus::Valid)
        ->and($batch->refresh()->rows_valid)->toBe(2);
});

it('B.18: a fixed cell is stored neutralised — never a live formula', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    app(FixImportRow::class)->handle($login, $batch->code, 2, 'about', '=HYPERLINK("x")');

    expect(rowAt($batch, 2)->data['raw']['about'] ?? null)->toBe('\'=HYPERLINK("x")');
});

it('B.9 step 3 refusals: a column not in the file, a too-long value, a batch not READY, an imported row', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    expect(fn () => app(FixImportRow::class)->handle($login, $batch->code, 2, 'status', 'ACTIVE'))->toThrow(ValidationException::class)
        ->and(fn () => app(FixImportRow::class)->handle($login, $batch->code, 2, 'height', str_repeat('x', 501)))->toThrow(ValidationException::class)
        ->and(fn () => app(FixImportRow::class)->handle($login, $batch->code, 99, 'height', '163'))->toThrow(ModelNotFoundException::class);

    rowAt($batch, 2)->forceFill(['status' => ImportRowStatus::Imported])->save();
    expect(fn () => app(FixImportRow::class)->handle($login, $batch->code, 2, 'height', '163'))->toThrow(ModelNotFoundException::class);

    $batch->forceFill(['status' => ImportBatchStatus::Completed])->save();
    expect(fn () => app(FixImportRow::class)->handle($login, $batch->code, 2, 'height', '163'))->toThrow(ImportRefused::class);
});

it('R-M13-18 / R-M13-26: another bureau’s batch is 404; a Telecaller is 403; nothing changes', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    $stranger = bureauLogin(BureauRole::Owner);
    app(BureauGate::class)->forget();
    expect(fn () => app(FixImportRow::class)->handle($stranger, $batch->code, 2, 'height', '163'))->toThrow(ModelNotFoundException::class);

    $telecaller = bureauLogin(BureauRole::Telecaller, broker: staffOf($login)->broker);
    app(BureauGate::class)->forget();
    expect(fn () => app(FixImportRow::class)->handle($telecaller, $batch->code, 2, 'height', '163'))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(403));

    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Error);
});

/** The errors.xlsx shape: `row` + the batch's headers (+ messages, ignored). */
function fixesFile(array $rows): Illuminate\Http\UploadedFile
{
    return importXlsx($rows, ['row', ...ImportColumns::headers(), 'messages'], 'fixes.xlsx');
}

it('B.9 step 3: the fixed errors file replaces just its rows in the same batch and re-checks them', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall']), importRow(['first_name' => 'Meera', 'height' => 'short'], '9876500102'), importRow(['first_name' => 'Devi'], '9876500103')]);
    expect($batch->rows_error)->toBe(2);

    $count = app(ReuploadImportErrors::class)->handle($login, $batch->code, fixesFile([
        ['row' => 2, ...importRow(['height' => '160']), 'messages' => 'height: …'],
    ]));

    expect($count)->toBe(1)
        ->and(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Valid)
        ->and(rowAt($batch, 3)->status)->toBe(ImportRowStatus::Error)   // not in the file: untouched
        ->and(rowAt($batch, 4)->status)->toBe(ImportRowStatus::Valid)
        ->and($batch->refresh()->fix_sheet_path)->toStartWith('imports/'.$batch->id.'/')
        ->and(AuditLog::query()->where('action', 'broker.import_fixes_uploaded')->exists())->toBeTrue();
});

it('B.9 step 3: a row number not waiting in this batch refuses the whole file; no “row” column is refused; another bureau is 404', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    expect(fn () => app(ReuploadImportErrors::class)->handle($login, $batch->code, fixesFile([
        ['row' => 2, ...importRow()], ['row' => 77, ...importRow([], '9876500102')],
    ])))->toThrow(ValidationException::class);
    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Error);

    expect(fn () => app(ReuploadImportErrors::class)->handle($login, $batch->code, importXlsx([importRow()])))
        ->toThrow(ValidationException::class);

    $stranger = bureauLogin(BureauRole::Owner);
    app(BureauGate::class)->forget();
    expect(fn () => app(ReuploadImportErrors::class)->handle($stranger, $batch->code, fixesFile([['row' => 2, ...importRow()]])))
        ->toThrow(ModelNotFoundException::class);
});
