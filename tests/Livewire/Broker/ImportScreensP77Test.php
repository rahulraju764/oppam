<?php

declare(strict_types=1);

use App\Enums\BrokerKycStatus;
use App\Enums\BureauRole;
use App\Enums\Flag;
use App\Enums\ImportBatchStatus;
use App\Enums\ImportRowStatus;
use App\Enums\ImportsPauseReason;
use App\Jobs\Broker\ImportBatchRows;
use App\Livewire\Broker\Import\Batches;
use App\Livewire\Broker\Import\BatchReview;
use App\Models\BrokerImportBatch;
use App\Models\Profile;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
| P7.7 — the import screens, steps 3–6 (§11A B.9): BatchReview's inline fix, consent, import with
| live progress, imported profiles + "Submit all complete", cancel, the paused / KYC-revoked states,
| and the Batches history page. Rendering, actions through the Actions, scope, escaping, loading markup.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    flagOn(Flag::BrokerBulkImport);
    fakeMediaDisks();
    Queue::fake();
});

it('BatchReview: an ERROR row offers its flagged cells; saving re-checks it (toast) — with loading markup', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSeeHtml('id="fix-2-0"')->assertSee('height')
        ->assertSeeHtml('wire:target="fixCell"')
        ->call('fixCell', 2, 'height', '163')
        ->assertDispatched('toast', type: 'success');

    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Valid);
});

it('BatchReview: a fixed cell holding markup is shown escaped (XSS)', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = pipelineReady($login, [importRow(['height' => 'tall'])]);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->call('fixCell', 2, 'height', '<script>alert(1)</script>')
        ->assertDontSeeHtml('<script>alert(1)</script>')
        ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
});

it('BatchReview: consent needs the tick, then Import queues the job and shows live import progress (R-M13-27)', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = pipelineReady($login, [importRow()]);

    $page = Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Confirm consent')->assertDontSee('Import now')
        ->call('attest')->assertHasErrors('consent')
        ->set('consent', true)->call('attest')->assertDispatched('toast', type: 'success')
        ->assertSee('Import now')->assertSee('1 row will become a draft profile.')
        ->call('import')->assertDispatched('toast', type: 'success');

    Queue::assertPushed(ImportBatchRows::class, fn (ImportBatchRows $job): bool => $job->batchId === $batch->id);
    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Importing);
    $page->assertSee('Importing: 0 of 1 profiles added')->assertSeeHtml('wire:poll');
    expect($page->instance()->getListeners())
        ->toHaveKey('echo-private:broker.'.staffOf($login)->broker_id.',.import.completed')
        ->toHaveKey('echo-private:broker.'.staffOf($login)->broker_id.',.imports.paused');
});

it('BatchReview: someone other than the uploader sees why they can’t confirm consent or import', function (): void {
    $uploader = bureauLogin(BureauRole::DataEntry);
    $batch = pipelineReady($uploader, [importRow()]);
    actingAsBureau(bureauLogin(BureauRole::Owner, broker: staffOf($uploader)->broker));

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Only the person who uploaded this file can confirm consent and import it.')
        ->assertDontSee('Confirm consent')
        ->assertSee('Cancel batch');   // Owner may cancel
});

it('BatchReview: after import the imported rows link to their profiles and “Submit all complete” reports what it did', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = importNow($login, pipelineReady($login, [importRow()]));
    $code = Profile::query()->where('import_batch_id', $batch->id)->value('code');

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Open profile '.$code)
        ->assertSeeHtml(route('broker.profiles.show', ['code' => $code]))
        ->assertSee('Submit all complete profiles')
        ->call('submitAll')
        ->assertDispatched('toast', type: 'info');   // nothing complete yet: 0 sent, 1 needs details
});

it('BatchReview: a paused bureau sees the banner and a disabled Import', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = pipelineReady($login, [importRow()]);
    app(App\Actions\Broker\Import\AttestBatchConsent::class)->handle($login, $batch->code);
    staffOf($login)->broker->forceFill(['imports_paused_at' => now(), 'imports_paused_reason' => ImportsPauseReason::Auto])->save();

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('Imports are paused for your bureau.')
        ->assertSee(ImportsPauseReason::Auto->label())
        ->assertSeeHtml('disabled')
        ->call('import')->assertDispatched('toast', type: 'danger');

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Ready);
});

it('BatchReview: KYC revoked → summary and Cancel only, no client rows (B.9, R-M13-21)', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $batch = pipelineReady($login, [importRow()]);
    staffOf($login)->broker->forceFill(['kyc_status' => BrokerKycStatus::Revoked])->save();
    app(App\Domain\Broker\BureauGate::class)->forget();

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSee('can’t import profiles right now')
        ->assertDontSee('Row 2')->assertDontSee('Anjali')->assertDontSee('Confirm consent')
        ->call('cancel')->assertDispatched('toast', type: 'success');

    expect($batch->refresh()->status)->toBe(ImportBatchStatus::Cancelled);
});

it('Batches: the bureau’s own batches with counts, status and approval rate; another bureau’s are never listed', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $broker = staffOf($login)->broker;
    $mine = BrokerImportBatch::factory()->create(['broker_id' => $broker->id, 'uploaded_by_user_id' => $login->id, 'status' => ImportBatchStatus::Completed,
        'rows_valid' => 0, 'rows_imported' => 12, 'rows_approved' => 3, 'rows_rejected' => 1]);
    $theirs = BrokerImportBatch::factory()->create(['status' => ImportBatchStatus::Ready]);

    Livewire::test(Batches::class)
        ->assertSee('Import batches')
        ->assertSee($mine->code)->assertSeeHtml(route('broker.import.batch', ['batch' => $mine->code]))
        ->assertSee('75%')
        ->assertDontSee($theirs->code);
});

it('Batches: the empty state, and pages of 25', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    Livewire::test(Batches::class)->assertSee('No batches yet');

    BrokerImportBatch::factory()->count(26)->create(['broker_id' => staffOf($login)->broker_id, 'uploaded_by_user_id' => $login->id]);
    Livewire::test(Batches::class)->assertSeeHtml('wire:target="gotoPage, nextPage, previousPage"')
        ->call('nextPage')->assertSeeHtml('wire:key="batch-');
});

it('Batches: a Telecaller is 403; with the flag off it is 404', function (): void {
    $owner = bureauLogin();
    actingAsBureau(bureauLogin(BureauRole::Telecaller, broker: staffOf($owner)->broker));
    Livewire::test(Batches::class)->assertForbidden();

    actingAsBureau($owner);
    App\Models\FeatureFlag::query()->where('key', Flag::BrokerBulkImport->value)->update(['is_enabled' => false]);
    app(App\Services\Settings\FeatureFlags::class)->flush();
    Livewire::test(Batches::class)->assertNotFound();
});

it('BatchReview: the Fix editor finds the cell when the sheet calls the column something else (mapped headers)', function (): void {
    $login = actingAsBureau(bureauLogin(BureauRole::DataEntry));
    $row = importRow(['height' => 'tall']);
    $odd = ['Ht' => $row['height']] + array_diff_key($row, ['height' => true]);
    $batch = app(App\Actions\Broker\Import\StoreImportBatch::class)->handle($login, importXlsx([$odd], array_keys($odd)), null);
    app()->call([new App\Jobs\Broker\ParseImportBatch($batch->id), 'handle']);
    app(App\Actions\Broker\Import\SaveColumnMapping::class)->handle($login, $batch->code, [...array_combine(array_keys(App\Domain\Broker\Import\ImportColumns::autoMap(array_keys($odd))), App\Domain\Broker\Import\ImportColumns::autoMap(array_keys($odd))), 'height_cm' => 'Ht']);
    app()->call([new App\Jobs\Broker\ParseImportBatch($batch->id), 'handle']);

    Livewire::test(BatchReview::class, ['batch' => $batch->code])
        ->assertSeeHtml('<label for="fix-2-0">Ht</label>')
        ->call('fixCell', 2, 'Ht', '163');

    expect(rowAt($batch, 2)->status)->toBe(ImportRowStatus::Valid);
});
