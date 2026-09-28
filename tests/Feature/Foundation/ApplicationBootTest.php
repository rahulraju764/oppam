<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

it('serves the public home page', function (): void {
    $this->get('/')->assertOk();
});

it('answers the uptime probe', function (): void {
    $this->get('/up')->assertOk()->assertSee('OK');
});

it('stores time in UTC and displays it in IST', function (): void {
    expect(config('app.timezone'))->toBe('UTC')
        ->and(config('oppam.display_timezone'))->toBe('Asia/Kolkata');
});

it('runs on the MariaDB/MySQL test database with utf8mb4, never the development database', function (): void {
    // Parallel runs use per-process copies: oppam_testing_test_1, _2, …
    expect(DB::connection()->getDriverName())->toBeIn(['mariadb', 'mysql'])
        ->and(DB::connection()->getDatabaseName())->toStartWith('oppam_testing')
        ->and(DB::connection()->getDatabaseName())->not->toBe('oppam')   // the development database
        ->and(DB::connection()->getConfig('charset'))->toBe('utf8mb4');

    DB::select('select 1');   // the connection actually works
});
