<?php

declare(strict_types=1);

use App\Actions\Reports\ExportReport;
use App\Data\Reports\ReportTableData;
use App\Domain\Reports\ReportRange;
use App\Enums\AdminExportStatus;
use App\Enums\ExportFormat;
use App\Enums\ReportKey;
use App\Http\Controllers\Admin\ReportExportController;
use App\Jobs\Reports\ExpireAdminExports;
use App\Jobs\Reports\GenerateReportExport;
use App\Livewire\Admin\Reports\ReportView;
use App\Models\AuditLog;
use App\Notifications\Admin\ReportExportReady;
use App\Services\Reports\ReportFileWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\IOFactory;

/*
| P9.1a — A09 export (PRD: "CSV/Excel export with formula neutralisation, audited, large exports
| queued & emailed"): every cell neutralised (CSV) or written as text (Excel); small exports
| stream now through a single-use signed link; large ones are built in the background, the admin
| is emailed, only they can download it for 24 hours; reports.export required; all audited.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Storage::fake((string) config('oppam.media.private_disk'));
    $this->admin = adminWithRole('super_admin');
    signInAdmin($this, $this->admin);
    $this->range = ReportRange::of('2026-11-01', '2026-11-07');
});

/** @return list<ReportTableData> */
function evilTables(): array
{
    return [new ReportTableData('t', '=Title', ['Name', 'Value'], [
        ['=HYPERLINK("http://evil","x")', 1], ['+1+2', 2], ['-cmd', 3], ['@SUM(A1)', 4.5], ["\tTab", null], ['safe', 0],
    ])];
}

it('A09 export CSV: every text cell that could start a formula gets a leading apostrophe; numbers stay numbers; UTF-8 BOM', function (): void {
    $stream = fopen('php://memory', 'w+b');
    $rows = app(ReportFileWriter::class)->write(evilTables(), ExportFormat::Csv, $stream, '@title');
    rewind($stream);
    $csv = (string) stream_get_contents($stream);

    expect($rows)->toBe(6)
        ->and(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($csv)->toContain("'=HYPERLINK")->toContain("'+1+2")->toContain("'-cmd")->toContain("'@SUM(A1)")->toContain("'=Title")->toContain("'@title")
        ->and($csv)->toContain('safe,0')->toContain(',4.5');
});

it('A09 export Excel: text cells are stored as text (never a formula), numbers as numbers', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'rx');
    $stream = fopen($path, 'w+b');
    app(ReportFileWriter::class)->write(evilTables(), ExportFormat::Xlsx, $stream, 'T');
    fclose($stream);

    $book = IOFactory::load($path);
    expect($book->getSheet(0)->getTitle())->toBe('Report')   // cover sheet with the (neutralised) title
        ->and((string) $book->getSheet(0)->getCell('A1')->getValue())->toBe('T');
    $sheet = $book->getSheet(1);
    expect($sheet->getCell('A2')->getDataType())->toBe('s')
        ->and((string) $sheet->getCell('A2')->getValue())->toBe("'=HYPERLINK(\"http://evil\",\"x\")")
        ->and($sheet->getCell('B5')->getValue())->toBe(4.5)
        ->and($sheet->getCell('B5')->getDataType())->toBe('n');
    unlink($path);
});

it('A09 small export: the report page hands out a 5-minute signed, single-use link; the file streams and is audited', function (): void {
    $page = Livewire::test(ReportView::class, ['report' => 'growth'])->call('export', 'csv');
    $url = $page->effects['redirect'] ?? null;
    expect($url)->toContain('/reports/export');

    $response = $this->get($url)->assertOk();
    $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
    expect($response->streamedContent())->toContain('Signups by source');
    expect(AuditLog::query()->where('action', 'reports.exported')->sole()->after)->toMatchArray(['report' => 'growth', 'format' => 'csv']);

    $this->get($url)->assertNotFound();   // single use
});

it('A09 export link security: unsigned, tampered, another admin\'s token → refused', function (): void {
    Cache::put(ReportExportController::CACHE_PREFIX.str_repeat('a', 40), ['admin' => $this->admin->id, 'report' => 'growth', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-07'], 300);
    $signed = URL::temporarySignedRoute('admin.reports.export', now()->addMinutes(5), ['token' => str_repeat('a', 40)]);

    $this->get(route('admin.reports.export', ['token' => str_repeat('a', 40)]))->assertForbidden();   // unsigned
    $this->get($signed.'x')->assertForbidden();                                                       // tampered
    signInAdmin($this, adminWithRole('super_admin'));
    $this->get($signed)->assertNotFound();           // not theirs
});

it('A09 large export: queued, built on the private disk, its admin emailed (no token in the mail), downloadable 24 hours by them only, audited', function (): void {
    Queue::fake();
    Notification::fake();
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle($this->admin, ['reports.export_sync_max_rows' => 100], 'Test');
    // 120 signup-source rows → the growth export is over the 100-row limit.
    foreach (range(1, 120) as $i) {
        App\Models\ReportSnapshot::query()->insert(['metric' => 'signups', 'day' => '2026-11-02', 'dimension' => 'source:S'.$i, 'value' => 1, 'computed_at' => now()]);
    }

    $export = app(ExportReport::class)->handle($this->admin, ReportKey::Growth, $this->range, ExportFormat::Xlsx);
    expect($export)->not->toBeNull()->and($export->status)->toBe(AdminExportStatus::Queued);
    Queue::assertPushed(GenerateReportExport::class);

    app()->call([new GenerateReportExport($export->id), 'handle']);
    $export->refresh();
    expect($export->status)->toBe(AdminExportStatus::Ready)->and($export->rows)->toBeGreaterThan(100);
    Storage::disk((string) config('oppam.media.private_disk'))->assertExists($export->path);
    Notification::assertSentTo($this->admin, ReportExportReady::class, fn (ReportExportReady $n): bool => ! str_contains($n->toMail($this->admin)->actionUrl, 'token')
        && str_contains($n->toMail($this->admin)->actionUrl, '/reports/exports'));

    $this->get(route('admin.reports.exports.download', $export->code))->assertOk();
    expect(AuditLog::query()->where('action', 'reports.export_downloaded')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'reports.export_queued')->count())->toBe(1);

    signInAdmin($this, adminWithRole('super_admin'));
    $this->get(route('admin.reports.exports.download', $export->code))->assertNotFound();

    signInAdmin($this, $this->admin);
    $this->travel(25)->hours();
    signInAdmin($this, $this->admin);   // the admin session itself times out over 25 hours
    $this->get(route('admin.reports.exports.download', $export->code))->assertStatus(410);
    app()->call([new ExpireAdminExports, 'handle']);
    expect($export->refresh()->status)->toBe(AdminExportStatus::Expired);
    Storage::disk((string) config('oppam.media.private_disk'))->assertMissing('reports/exports/'.$export->code.'.xlsx');
});

it('A09 export permission: reports.view without reports.export → refused and audited; the buttons are hidden', function (): void {
    $viewer = adminWithRole('read_only');   // every *.view key, no reports.export
    signInAdmin($this, $viewer);

    Livewire::test(ReportView::class, ['report' => 'growth'])->assertOk()->assertDontSeeHtml('dusk="export-csv"')
        ->call('export', 'csv')->assertDispatched('toast', type: 'error');
    expect(fn () => app(ExportReport::class)->handle($viewer, ReportKey::Growth, $this->range, ExportFormat::Csv))->toThrow(AuthorizationException::class);
    expect(AuditLog::query()->where('action', 'reports.export_denied')->count())->toBe(2);

    $this->get(route('admin.reports.exports'))->assertForbidden();
});
