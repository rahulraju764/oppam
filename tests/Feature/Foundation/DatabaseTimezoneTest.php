<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| The database session runs in UTC like the app (CLAUDE.md "Dates: stored UTC"), so values the
| database fills in itself (CURRENT_TIMESTAMP defaults) mean the same instant as now().
*/

it('runs the database session in UTC', function (): void {
    $zone = DB::selectOne('select @@session.time_zone as tz')->tz;

    expect($zone)->toBe('+00:00');
});

it('reads a database-filled CURRENT_TIMESTAMP as the current UTC time', function (): void {
    $dbNow = Illuminate\Support\Carbon::parse(DB::selectOne('select CURRENT_TIMESTAMP as n')->n, 'UTC');

    expect(abs($dbNow->diffInSeconds(now())))->toBeLessThan(60);
});
