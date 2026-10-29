<?php

declare(strict_types=1);

use App\Actions\Broker\Import\CancelImportBatch;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Exceptions\Broker\ImportRefused;
use App\Jobs\Broker\PurgeImportFiles;
use App\Models\AuditLog;
use Database\Seeders\PlansSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
| P7.7 — §11A B.9 batch history: "cancel before import" (uploader, Owner, Manager) and "source files
| are purged from storage after 30 days" (PurgeImportFiles, daily 03:45 IST; B.23 retention setting).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    fakeMediaDisks();
    Queue::fake();
});

function importDisk(): Illuminate\Contracts\Filesystem\Filesystem
{
    return Storage::disk((string) config('oppam.media.private_disk'));
}

it('B.9 / B.23: files older than the retention are purged and stamped; newer ones stay; a re-run is safe', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $old = pipelineReady($login, [importRow()], importZip(['a.jpg' => 'x']));
    $new = pipelineReady($login, [importRow([], '9876500102')]);
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    expect(importDisk()->exists((string) $old->sheet_path))->toBeTrue();

    app()->call([new PurgeImportFiles, 'handle']);
    app()->call([new PurgeImportFiles, 'handle']);

    expect(importDisk()->allFiles('imports/'.$old->id))->toBe([])
        ->and($old->refresh()->files_purged_at)->not->toBeNull()
        ->and([$old->sheet_path, $old->photos_zip_path])->toBe([null, null])
        ->and(importDisk()->exists((string) $new->refresh()->sheet_path))->toBeTrue()
        ->and($new->files_purged_at)->toBeNull()
        ->and(rowAt($old, 2)->status)->toBe(ImportRowStatus::Valid)   // rows and profiles stay
        ->and(AuditLog::query()->where('action', 'broker.import_files_purged')->count())->toBe(1);
});

it('B.23: the purge runs daily at 03:45 IST', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->description, 'PurgeImportFiles'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('45 3 * * *')
        ->and($event->timezone)->toBe('Asia/Kolkata');
});

it('B.9 cancel: the uploader cancels a READY batch — CANCELLED, files deleted, audited; Owner and Manager may too, other staff may not', function (): void {
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($login, [importRow()]);

    $other = bureauLogin(BureauRole::DataEntry, broker: staffOf($login)->broker);
    app(BureauGate::class)->forget();
    expect(fn () => app(CancelImportBatch::class)->handle($other, $batch->code))->toThrow(ImportRefused::class);

    app(BureauGate::class)->forget();
    $done = app(CancelImportBatch::class)->handle($login, $batch->code);
    expect($done->status)->toBe(ImportBatchStatus::Cancelled)
        ->and(importDisk()->allFiles('imports/'.$batch->id))->toBe([])
        ->and(AuditLog::query()->where('action', 'broker.import_cancelled')->exists())->toBeTrue();

    $second = pipelineReady($login, [importRow([], '9876500102')]);
    $manager = bureauLogin(BureauRole::Manager, broker: staffOf($login)->broker);
    app(BureauGate::class)->forget();
    expect(app(CancelImportBatch::class)->handle($manager, $second->code)->status)->toBe(ImportBatchStatus::Cancelled);
});

it('B.9 cancel: a partly imported batch is closed instead — the profiles stay, the rest is skipped; a COMPLETED batch can’t be cancelled', function (): void {
    settingIs(App\Enums\SettingKey::BrokerImportRowsPerDayNew, 1);
    $login = bureauLogin(BureauRole::DataEntry);
    $batch = importNow($login, pipelineReady($login, [importRow(), importRow(['first_name' => 'Meera'], '9876500102')]));
    expect($batch->rows_imported)->toBe(1);

    app(BureauGate::class)->forget();
    $closed = app(CancelImportBatch::class)->handle($login, $batch->code);

    expect($closed->status)->toBe(ImportBatchStatus::Completed)
        ->and([$closed->rows_imported, $closed->rows_skipped])->toBe([1, 1])
        ->and(App\Models\Profile::query()->where('import_batch_id', $batch->id)->count())->toBe(1);

    app(BureauGate::class)->forget();
    expect(fn () => app(CancelImportBatch::class)->handle($login, $batch->code))->toThrow(ImportRefused::class);
});
