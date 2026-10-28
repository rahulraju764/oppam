<?php

declare(strict_types=1);

use App\Actions\Broker\Import\StoreImportBatch;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\ParseImportBatch;
use App\Models\AuditLog;
use App\Models\BrokerImportBatch;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.6 — StoreImportBatch, step 1 of a bulk import (§11A B.9, B.17, R-M13-26, R-A07-9): who may
| upload, and file safety decided from the bytes (real xlsx / UTF-8 csv ≤ 10 MB; a photo zip of
| photos only, no nested archive, no zip-slip path, entries ≤ 10 MB). Nothing becomes a profile.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    Storage::fake((string) config('oppam.media.private_disk'));
    Queue::fake();
});

function storeBatch(App\Models\User $login, ?UploadedFile $sheet = null, ?UploadedFile $zip = null): BrokerImportBatch
{
    return app(StoreImportBatch::class)->handle($login, $sheet ?? importXlsx([importRow()]), $zip);
}

it('B.9 step 1: an upload becomes batch B-0001, UPLOADED, files on the private disk, parsing queued on `imports`, audited', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $sheet = importXlsx([importRow()]);
    $sha = hash_file('sha256', (string) $sheet->getRealPath());

    $batch = storeBatch($login, $sheet, importZip(['anjali_1.jpg' => 'jpeg-bytes']));

    expect($batch->code)->toMatch('/^B-\d{4}$/')
        ->and($batch->status)->toBe(ImportBatchStatus::Uploaded)
        ->and($batch->broker_id)->toBe(staffOf($login)->broker_id)
        ->and($batch->uploaded_by_user_id)->toBe($login->id)
        ->and($batch->file_sha256)->toBe($sha)
        ->and($batch->sheet_path)->toBe('imports/'.$batch->id.'/sheet.xlsx.enc')
        ->and($batch->photos_zip_path)->toBe('imports/'.$batch->id.'/photos.zip.enc')
        ->and($batch->duplicate_of_batch_id)->toBeNull();
    Storage::disk((string) config('oppam.media.private_disk'))->assertExists([$batch->sheet_path, $batch->photos_zip_path]);
    Queue::assertPushedOn('imports', ParseImportBatch::class, fn (ParseImportBatch $job): bool => $job->batchId === $batch->id);
    expect(AuditLog::query()->where('action', 'broker.import_uploaded')->where('actor_id', $login->id)->exists())->toBeTrue()
        ->and(App\Models\Profile::query()->count())->toBe(0);
});

it('a CSV (UTF-8) is accepted; the same file again is remembered for a banner', function (): void {
    $login = bureauLogin();
    $rows = [importRow(['first_name' => 'അഞ്ജലി'])];

    $first = storeBatch($login, importCsv($rows));
    $again = storeBatch($login, importCsv($rows));

    expect($first->sheet_path)->toEndWith('sheet.csv.enc')
        ->and($again->duplicate_of_batch_id)->toBe($first->id)
        ->and($again->code)->not->toBe($first->code);
});

it('R-M13-26 / B.2: `profiles.import` — Telecaller 403, KYC pending 403 BROKER_KYC_REQUIRED, other roles allowed', function (): void {
    expect(fn () => storeBatch(bureauLogin(BureauRole::Telecaller)))->toThrow(HttpException::class);
    expect(fn () => storeBatch(bureauLogin(BureauRole::Owner, 'kyc_pending')))->toThrow(HttpException::class);

    foreach ([BureauRole::Owner, BureauRole::Manager, BureauRole::DataEntry] as $role) {
        expect(storeBatch(bureauLogin($role))->exists)->toBeTrue();
    }
});

it('R-A07-9: imports paused by A07 refuse uploads; the flag off refuses them too', function (): void {
    $login = bureauLogin();
    staffOf($login)->broker->forceFill(['imports_paused_at' => now()])->save();

    expect(fn () => storeBatch($login))->toThrow(ImportRefused::class, 'paused');

    App\Models\FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    expect(fn () => storeBatch(bureauLogin()))->toThrow(ImportRefused::class, 'coming soon');

    expect(BrokerImportBatch::query()->count())->toBe(0);
});

it('B.17: the sheet is judged by its bytes — renamed scripts / HTML / wrong types refused, nothing stored', function (string $name, string $bytes): void {
    $path = tempnam(sys_get_temp_dir(), 'bad');
    file_put_contents($path, $bytes);

    try {
        storeBatch(bureauLogin(), new UploadedFile($path, $name, null, null, true));
        $this->fail('expected a refusal');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('sheet');
    }

    expect(BrokerImportBatch::query()->count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'php renamed .xlsx' => ['clients.xlsx', '<?php system($_GET["c"]); ?>'],
    'html renamed .xlsx' => ['clients.xlsx', '<html><script>alert(1)</script></html>'],
    'a zip that is not a workbook' => ['clients.xlsx', (function (): string {
        $p = tempnam(sys_get_temp_dir(), 'z');
        $z = new ZipArchive;
        $z->open($p, ZipArchive::OVERWRITE);
        $z->addFromString('hello.txt', 'hi');
        $z->close();

        return (string) file_get_contents($p);
    })()],
    'binary renamed .csv' => ['clients.csv', "\x00\x01\x02\xff\xfe"],
    'a .pdf' => ['clients.pdf', '%PDF-1.4'],
]);

it('B.9 limits: a sheet over 10 MB or a zip over 200 MB is refused', function (): void {
    $big = UploadedFile::fake()->create('clients.xlsx', 10241);
    expect(fn () => storeBatch(bureauLogin(), $big))->toThrow(ValidationException::class);

    $hugeZip = UploadedFile::fake()->create('photos.zip', 204801);
    expect(fn () => storeBatch(bureauLogin(), null, $hugeZip))->toThrow(ValidationException::class);
});

it('B.17 zip safety: zip-slip paths, nested archives, non-photos and oversized entries are refused', function (array $entries): void {
    try {
        storeBatch(bureauLogin(), null, importZip($entries));
        $this->fail('expected a refusal');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('photos');
    }

    expect(BrokerImportBatch::query()->count())->toBe(0);
})->with([
    'zip-slip ..' => [['../../evil.jpg' => 'x']],
    'absolute path' => [['/etc/evil.jpg' => 'x']],
    'nested archive' => [['inner.zip' => 'PK']],
    'a script' => [['run.php' => '<?php']],
    'an svg' => [['photo.svg' => '<svg/>']],
    'oversized entry' => [['big.jpg' => str_repeat('a', 10 * 1024 * 1024 + 1)]],
]);

it('a zip that is not really a zip is refused', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'z');
    file_put_contents($path, 'not a zip at all');

    expect(fn () => storeBatch(bureauLogin(), null, new UploadedFile($path, 'photos.zip', null, null, true)))
        ->toThrow(ValidationException::class);
});
