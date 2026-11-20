<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;

/**
 * For the few tests that need COMMITTED rows (tests/Committed): InnoDB FULLTEXT indexes only see
 * rows after commit, so the usual per-test transaction (LazilyRefreshDatabase) would hide them
 * (A12 audit search, P9.2a). Only the tables listed here are truncated, before AND after each test,
 * so nothing leaks into the transactional tests that share this process's database. A test here
 * must write to no other table.
 */
abstract class CommittedTestCase extends TestCase
{
    use DatabaseTruncation;

    /** @var list<string> */
    protected $tablesToTruncate = ['audit_logs'];

    protected function tearDown(): void
    {
        foreach ($this->tablesToTruncate as $table) {
            DB::statement('TRUNCATE TABLE '.$table);   // TRUNCATE fires no DELETE trigger
        }

        parent::tearDown();
    }
}
