<?php

declare(strict_types=1);

use App\Actions\Broker\Import\StoreImportBatch;
use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\ImportFileInspector;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Http\Middleware\LimitLargeLivewireUploads;
use App\Models\BrokerImportBatch;
use App\Models\User;
use App\Services\Broker\ImportFileVault;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
| P7.6 review round 1 — import files at rest and upload limits: CLAUDE.md security rule 7 / PRD
| §11A B.18 ("import files on the private disk, encrypted"), the 200 MB Livewire upload ceiling only
| for bureau importers, a per-login upload cap and the zip-bomb guard.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    Storage::fake((string) config('oppam.media.private_disk'));
    Queue::fake();
});

it('★ rule 7 / B.18: the sheet and the zip are ENCRYPTED at rest; the vault restores them byte for byte', function (): void {
    $sheet = importXlsx([importRow(['first_name' => 'Secretname'])]);
    $plain = (string) file_get_contents((string) $sheet->getRealPath());
    $zip = importZip(['anjali_1.jpg' => 'jpeg-bytes-here']);
    $zipPlain = (string) file_get_contents((string) $zip->getRealPath());

    $batch = app(StoreImportBatch::class)->handle(bureauLogin(), $sheet, $zip);
    $disk = Storage::disk((string) config('oppam.media.private_disk'));

    expect($disk->get($batch->sheet_path))->not->toBe($plain)
        ->and(str_starts_with((string) $disk->get($batch->sheet_path), 'PK'))->toBeFalse()
        ->and((string) $disk->get((string) $batch->photos_zip_path))->not->toContain('anjali_1.jpg');

    $vault = app(ImportFileVault::class);
    $restored = $vault->unseal($batch->sheet_path, 'xlsx');
    expect(file_get_contents($restored))->toBe($plain);
    ImportFileVault::forget($restored);
    expect(is_file($restored))->toBeFalse();

    $restoredZip = $vault->unseal((string) $batch->photos_zip_path, 'zip');
    expect(file_get_contents($restoredZip))->toBe($zipPlain);
    ImportFileVault::forget($restoredZip);
});

it('rule 7: every plain copy gets its own private folder — two unseals never share a path; forget() removes both file and folder', function (): void {
    $batch = app(StoreImportBatch::class)->handle(bureauLogin(), importXlsx([importRow()]), null);
    $vault = app(ImportFileVault::class);

    // P7.7 regression: a tempnam() name renamed to add ".xlsx" was free again for the next worker,
    // which could overwrite another batch's decrypted sheet while it was being read.
    $a = $vault->unseal($batch->sheet_path, 'xlsx');
    $b = $vault->unseal($batch->sheet_path, 'xlsx');
    expect($a)->not->toBe($b)
        ->and(dirname($a))->not->toBe(dirname($b))
        ->and(basename(dirname($a)))->toStartWith(ImportFileVault::TEMP_PREFIX);

    ImportFileVault::forget($a);
    expect(is_file($a))->toBeFalse()->and(is_dir(dirname($a)))->toBeFalse()
        ->and(is_file($b))->toBeTrue();
    ImportFileVault::forget($b);
    expect(is_dir(dirname($b)))->toBeFalse();

    expect(fn () => $vault->unseal($batch->sheet_path, '../x'))->toThrow(RuntimeException::class);
});

it('a stored file that was tampered with can’t be decrypted', function (): void {
    $batch = app(StoreImportBatch::class)->handle(bureauLogin(), importXlsx([importRow()]), null);
    Storage::disk((string) config('oppam.media.private_disk'))->put($batch->sheet_path, "tampered\n");

    expect(fn () => app(ImportFileVault::class)->unseal($batch->sheet_path, 'xlsx'))->toThrow(RuntimeException::class);
});

it('uploads are capped per login per hour', function (): void {
    $login = bureauLogin();
    for ($i = 0; $i < 20; $i++) {
        app(StoreImportBatch::class)->handle($login, importXlsx([importRow([], '98765'.str_pad((string) $i, 5, '0', STR_PAD_LEFT))]), null);
    }

    expect(fn () => app(StoreImportBatch::class)->handle($login, importXlsx([importRow()]), null))->toThrow(ValidationException::class);
    expect(BrokerImportBatch::query()->count())->toBe(20);
});

it('B.17 zip: zip-slip paths are recognised; the declared unpacked total is capped (zip-bomb guard)', function (): void {
    expect(ImportFileInspector::TOTAL_MAX_BYTES)->toBe(1024 * 1024 * 1024)
        ->and(ImportFileInspector::unsafePath('a/../b.jpg'))->toBeTrue()
        ->and(ImportFileInspector::unsafePath('C:/b.jpg'))->toBeTrue()
        ->and(ImportFileInspector::unsafePath('a\\b.jpg'))->toBeTrue()
        ->and(ImportFileInspector::unsafePath('photos/b.jpg'))->toBeFalse();
});

it('only a bureau importer may send a Livewire upload over 12 MB; members, other roles and guests get 413', function (): void {
    $middleware = app(LimitLargeLivewireUploads::class);
    $request = function (?User $user, int $bytes): Request {
        $r = Request::create('/livewire/upload-file', 'POST', server: ['CONTENT_LENGTH' => (string) $bytes]);
        $r->setUserResolver(fn () => $user);

        return $r;
    };
    $ok = fn () => response('ok');
    $big = 13 * 1024 * 1024;

    expect($middleware->handle($request(bureauLogin(BureauRole::DataEntry), $big), $ok)->getStatusCode())->toBe(200);

    foreach ([groom(), bureauLogin(BureauRole::Telecaller), null] as $user) {
        app(BureauGate::class)->forget();
        expect(fn () => $middleware->handle($request($user, $big), $ok))
            ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(413));
    }

    // No Content-Length (chunked body): the parsed files' sizes decide instead.
    $chunked = Request::create('/livewire/upload-file', 'POST', files: ['files' => [UploadedFile::fake()->create('big.zip', 13 * 1024)]]);
    $chunked->headers->remove('Content-Length');
    $chunked->setUserResolver(fn () => groom('+919800000124'));
    app(BureauGate::class)->forget();
    expect(fn () => $middleware->handle($chunked, $ok))
        ->toThrow(fn (HttpException $e) => expect($e->getStatusCode())->toBe(413));

    // Normal-size uploads pass for anyone (member photos, documents).
    expect($middleware->handle($request(groom('+919800000123'), 1024), $ok)->getStatusCode())->toBe(200);
    expect(config('livewire.temporary_file_upload.middleware'))->toContain(LimitLargeLivewireUploads::class);
});
