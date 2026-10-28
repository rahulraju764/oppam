<?php

declare(strict_types=1);

use App\Actions\Broker\Import\StoreImportBatch;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Jobs\Broker\ParseImportBatch;
use App\Livewire\Broker\Import\BatchReview;
use App\Livewire\Broker\Import\Upload;
use App\Models\BrokerImportBatch;
use App\Models\User;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P7.6 — the import screens (§11A B.9 steps 1–3, B.11 `profiles.import`): Upload (template links,
| the form, flag / paused states) and BatchReview (live progress, column matching, the row preview
| with filters and messages, errors.xlsx). Rendering, states, locked code, scope, loading markup.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    Storage::fake((string) config('oppam.media.private_disk'));
    Queue::fake();
});

function importActing(BureauRole $role = BureauRole::DataEntry): User
{
    return actingAsBureau(bureauLogin($role));
}

function readyBatch(User $login, array $rows): BrokerImportBatch
{
    $batch = app(StoreImportBatch::class)->handle($login, importXlsx($rows), null);
    app()->call([new ParseImportBatch($batch->id), 'handle']);

    return $batch->refresh();
}

it('Upload: template links, the form with limits, loading markup', function (): void {
    importActing();

    Livewire::test(Upload::class)
        ->assertSee('1. Download the template')
        ->assertSeeHtml(route('broker.import.template.xlsx'))
        ->assertSee('Up to 500 rows and 10 MB.')
        ->assertSeeHtml('wire:loading');
});

it('Upload: a sheet goes to its batch page; a missing or bad sheet stays inline', function (): void {
    importActing();

    Livewire::test(Upload::class)->call('startImport')->assertHasErrors(['sheet' => 'required']);

    Livewire::test(Upload::class)->set('sheet', Illuminate\Http\UploadedFile::fake()->createWithContent('a.xlsx', '<?php echo 1;'))
        ->call('startImport')->assertHasErrors(['sheet']);

    $real = importXlsx([importRow()]);
    Livewire::test(Upload::class)->set('sheet', Illuminate\Http\UploadedFile::fake()->createWithContent('clients.xlsx', (string) file_get_contents((string) $real->getRealPath())))
        ->call('startImport')
        ->assertRedirect(route('broker.import.batch', ['batch' => BrokerImportBatch::query()->sole()->code]));
});

it('Upload: flag off and paused imports explain themselves and offer no form', function (): void {
    $login = importActing(BureauRole::Owner);
    staffOf($login)->broker->forceFill(['imports_paused_at' => now()])->save();
    Livewire::test(Upload::class)->assertSee('Imports are paused')->assertDontSee('Upload and check');

    App\Models\FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    Livewire::test(Upload::class)->assertSee('Bulk import is coming soon');
});

it('BatchReview: READY shows counts, filters rows, and each row’s messages', function (): void {
    $login = importActing();
    $batch = readyBatch($login, [importRow(), importRow(['height' => 'tall'], '9876500102')]);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Batch '.$batch->code)
        ->assertSee('Download rows to fix (errors.xlsx)')
        ->assertSee('Row 2')->assertSee('Row 3')
        ->set('filter', 'error')->assertDontSee('Row 2')->assertSee('Row 3')->assertSee('Write the height in cm')
        ->set('filter', 'bogus')->assertSet('filter', 'all')
        ->assertSeeHtml('wire:loading.class="is-loading"');
});

it('BatchReview: while validating it shows the progress bar; it listens on the bureau channel', function (): void {
    $login = importActing();
    $batch = app(StoreImportBatch::class)->handle($login, importXlsx([importRow()]), null);

    $page = Livewire::test(BatchReview::class, ['batch' => $batch->code])->assertSee('Reading the file');
    expect($page->instance()->getListeners())->toHaveKey('echo-private:broker.'.staffOf($login)->broker_id.',.import.progress');
});

it('BatchReview: MAPPING shows the column form; saving queues the check again', function (): void {
    $login = importActing();
    $row = importRow();
    $odd = ['Kutty name' => $row['first_name']] + array_diff_key($row, ['first_name' => true]);
    $batch = app(StoreImportBatch::class)->handle($login, importXlsx([$odd], array_keys($odd)), null);
    app()->call([new ParseImportBatch($batch->id), 'handle']);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Tell us which column holds each field')
        ->call('saveMapping')->assertHasErrors(['mapping.first_name'])
        ->set('mapping.first_name', 'Kutty name')->call('saveMapping')->assertHasNoErrors()->assertDispatched('toast');

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Uploaded);
});

it('BatchReview: the code is locked; another bureau’s batch is 404; a Telecaller is 403', function (): void {
    $login = importActing();
    $mine = readyBatch($login, [importRow()]);
    $theirs = readyBatch(bureauLogin(), [importRow([], '9876500999')]);
    actingAsBureau($login);

    $page = Livewire::test(BatchReview::class, ['batch' => $mine->code]);
    expect(fn () => $page->set('code', $theirs->code))->toThrow(CannotUpdateLockedPropertyException::class);
    Livewire::test(BatchReview::class, ['batch' => $theirs->code])->assertNotFound();

    actingAsBureau(bureauLogin(BureauRole::Telecaller, broker: staffOf($login)->broker));
    Livewire::test(BatchReview::class, ['batch' => $mine->code])->assertForbidden();
});

it('errors.xlsx: the ERROR / WARNING rows with messages, neutralised, audited; another bureau’s batch is 404', function (): void {
    $login = importActing();
    $batch = readyBatch($login, [importRow(), importRow(['first_name' => '=cmd()', 'height' => 'tall'], '9876500102')]);

    $response = $this->get(brokerUrl('/import/'.$batch->code.'/errors.xlsx'))->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="'.$batch->code.'-errors.xlsx"');
    $path = tempnam(sys_get_temp_dir(), 'err').'.xlsx';
    file_put_contents($path, $response->getContent());
    $book = PhpOffice\PhpSpreadsheet\IOFactory::load($path);
    $rows = $book->getActiveSheet()->toArray(null, false, false, false);
    $book->disconnectWorksheets();

    expect($rows)->toHaveCount(2)                                   // header + the one bad row
        ->and($rows[1][0])->toBe('3')
        ->and(collect($rows[1])->contains("'=cmd()"))->toBeTrue()   // never a live formula
        ->and((string) end($rows[1]))->toContain('height:')
        ->and(App\Models\AuditLog::query()->where('action', 'broker.import_errors_downloaded')->exists())->toBeTrue();

    $other = readyBatch(bureauLogin(), [importRow([], '9876500998')]);
    actingAsBureau($login);
    $this->get(brokerUrl('/import/'.$other->code.'/errors.xlsx'))->assertNotFound();
});

it('XSS: cell contents shown in the preview are escaped', function (): void {
    $login = importActing();
    $batch = readyBatch($login, [importRow(['first_name' => '<script>alert(1)</script>'])]);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('BatchReview back-fills after a reconnect, and polls slowly only while the batch is being checked', function (): void {
    $login = importActing();
    $working = app(StoreImportBatch::class)->handle($login, importXlsx([importRow()]), null);

    $page = Livewire::test(BatchReview::class, ['batch' => $working->code])->assertSeeHtml('wire:poll.10s.visible');
    expect($page->instance()->getListeners())->toHaveKey('realtime-reconnected');

    $ready = readyBatch($login, [importRow([], '9876500555')]);
    Livewire::test(BatchReview::class, ['batch' => $ready->code])->assertDontSeeHtml('wire:poll');
});

it('Upload: the plain temporary uploads are deleted once the batch holds its encrypted copies', function (): void {
    importActing();
    $real = importXlsx([importRow()]);
    $page = Livewire::test(Upload::class)
        ->set('sheet', Illuminate\Http\UploadedFile::fake()->createWithContent('clients.xlsx', (string) file_get_contents((string) $real->getRealPath())));
    $temporary = $page->instance()->sheet;
    expect($temporary)->toBeInstanceOf(TemporaryUploadedFile::class)
        ->and($temporary->exists())->toBeTrue();

    $page->call('startImport');

    expect($temporary->exists())->toBeFalse()->and($page->get('sheet'))->toBeNull();
});

it('with the flag off, batch pages and errors.xlsx are 404', function (): void {
    $login = importActing();
    $batch = readyBatch($login, [importRow()]);
    App\Models\FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();

    Livewire::test(BatchReview::class, ['batch' => $batch->code])->assertNotFound();
    $this->get(brokerUrl('/import/'.$batch->code.'/errors.xlsx'))->assertNotFound();
});
