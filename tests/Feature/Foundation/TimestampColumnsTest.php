<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/*
| P0.6 — MariaDB/MySQL silently add ON UPDATE CURRENT_TIMESTAMP to the first NOT NULL TIMESTAMP
| column without a default (explicit_defaults_for_timestamp off). That would rewrite expiry /
| period columns on every update. Guard: no column in our schema has it (docs/decisions.md).
*/

it('has no column that auto-updates to the current time', function (): void {
    DB::connection()->getSchemaBuilder()->getTables();   // run migrations (lazy refresh)

    $columns = DB::select(
        "select table_name as t, column_name as c from information_schema.columns
         where table_schema = database() and lower(extra) like '%on update%'"
    );

    expect(array_map(fn (object $row): string => $row->t.'.'.$row->c, $columns))->toBe([]);
});
