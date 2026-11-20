<?php

declare(strict_types=1);

use App\Domain\Reports\ReportRange;
use App\Domain\Reports\Reports\ReportRegistry;
use App\Enums\ReportKey;
use Illuminate\Support\Facades\DB;

/*
| P9.1b review round 1 (Blocker): the Trust & ops report asked for 11 metrics at once and the
| metric list went into the cache key verbatim, so the key outgrew the database cache store's
| 255-character `cache.key` column and /reports/trust returned 500 wherever CACHE_STORE=database
| (the local .env). The suite runs on the array store, so these tests switch to the database store.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    config(['cache.default' => 'database']);
});

it('builds every A09 report on the database cache store', function (ReportKey $key): void {
    $report = app(ReportRegistry::class)->for($key)
        ->build(ReportRange::of('2026-10-01', '2026-11-07'), true, adminWithRole('super_admin'));

    expect($report->tables)->not->toBeEmpty();
})->with(ReportKey::cases());

it('keeps every cached report key within the cache table column', function (): void {
    $registry = app(ReportRegistry::class);
    $viewer = adminWithRole('super_admin');

    foreach (ReportKey::cases() as $key) {
        $registry->for($key)->build(ReportRange::of('2026-10-01', '2026-11-07'), true, $viewer);
    }

    $keys = DB::table('cache')->pluck('key');

    expect($keys)->not->toBeEmpty()
        ->and($keys->every(fn (string $k): bool => strlen($k) <= 255))->toBeTrue();
});
