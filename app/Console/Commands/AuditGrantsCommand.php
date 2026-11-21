<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\AuditPrivileges;
use App\Domain\Audit\AuditGrants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD A12 "append-only at model and DB grant level" (P9.2a). MySQL / MariaDB only. Three jobs:
 *
 *   php artisan audit:grants --user=oppam_app --host=10.0.%   prints the grant script for the
 *       application user (AuditGrants::script) — a DBA runs it as an admin user; P9.5's deploy
 *       script runs it after every migrate (new tables need their grant).
 *   php artisan audit:grants --check   connected as the APPLICATION user: fails (exit 1) when that
 *       user can change or wipe audit_logs (UPDATE, DELETE, DROP, ALTER, TRIGGER, ALL — see
 *       AuditGrants::violations).
 *   php artisan audit:grants --triggers [--connection=…]   connected as the MIGRATION / admin user:
 *       fails when an append-only trigger is missing. Separate from --check because MySQL / MariaDB
 *       only show a table's triggers to a user with the TRIGGER privilege — which the application
 *       user must not have, so it can never see them.
 *
 * The deploy smoke test runs both checks. It never changes grants itself.
 */
final class AuditGrantsCommand extends Command
{
    /** The triggers (migration 2026_09_28_110200_create_audit_logs_table) that make audit_logs append-only. */
    public const TRIGGERS = ['audit_logs_no_update', 'audit_logs_no_delete'];

    protected $signature = 'audit:grants
        {--check : fail when the connected (application) user can change or wipe audit_logs}
        {--triggers : fail when an append-only trigger is missing (run as the migration user)}
        {--connection= : the database connection to check (default: the application\'s)}
        {--user= : the application database user (script mode)}
        {--host=% : that user\'s host part (script mode)}';

    protected $description = 'Print or check the append-only database grants and triggers for audit_logs';

    public function handle(AuditPrivileges $privileges): int
    {
        $connection = $this->option('connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        if ($connection !== null && config('database.connections.'.$connection) === null) {
            $this->error('Unknown database connection: '.$connection);

            return self::FAILURE;
        }

        if (! in_array(DB::connection($connection)->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('audit:grants works on MySQL / MariaDB only.');

            return self::FAILURE;
        }

        return match (true) {
            (bool) $this->option('check') => $this->check($privileges, $connection),
            (bool) $this->option('triggers') => $this->triggers($privileges, $connection),
            default => $this->script(),
        };
    }

    private function script(): int
    {
        $user = trim((string) $this->option('user'));
        if ($user === '') {
            $this->error('Pass --user=<the application database user>.');

            return self::FAILURE;
        }

        $tables = array_values(array_map(
            fn (array $t): string => (string) $t['name'],
            Schema::getTables(DB::getDatabaseName()),
        ));
        sort($tables);

        $this->line('-- Oppam: per-table grants for the application user; audit_logs is SELECT / INSERT only (PRD A12).');
        $this->line('-- Run as a database admin. Re-run after every migrate. Migrations use a separate migration user.');
        foreach (AuditGrants::script(DB::getDatabaseName(), $user, (string) $this->option('host'), $tables) as $statement) {
            $this->line($statement);
        }

        return self::SUCCESS;
    }

    private function check(AuditPrivileges $privileges, ?string $connection): int
    {
        $violations = AuditGrants::violations($privileges->grants($connection), DB::connection($connection)->getDatabaseName());

        foreach ($violations as $line) {
            $this->error('Can change audit_logs: '.$line);
        }

        if ($violations !== []) {
            $this->line('Fix: php artisan audit:grants --user=<app user> and run the output as a database admin.');

            return self::FAILURE;
        }

        $this->info('audit_logs is append-only for this user (SELECT / INSERT only).');
        $this->line('Check the triggers too, as the migration user: php artisan audit:grants --triggers --connection=<migration connection>');

        return self::SUCCESS;
    }

    private function triggers(AuditPrivileges $privileges, ?string $connection): int
    {
        $missing = array_values(array_diff(self::TRIGGERS, $privileges->auditTriggers($connection)));

        foreach ($missing as $trigger) {
            $this->error('Missing trigger: '.$trigger);
        }

        if ($missing !== []) {
            $this->line('Either the trigger is gone (re-run the migrations) or this user cannot see it: triggers are only listed to a user with the TRIGGER privilege — run this as the migration user.');

            return self::FAILURE;
        }

        $this->info('Both append-only triggers are in place on audit_logs.');

        return self::SUCCESS;
    }
}
