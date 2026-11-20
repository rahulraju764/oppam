<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Audit\AuditGrants;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PRD A12 "append-only at model and DB grant level" (P9.2a). Two jobs, MySQL / MariaDB only:
 *
 *   php artisan audit:grants --user=oppam_app --host=10.0.%   prints the grant script for the
 *       application user (AuditGrants::script) — a DBA runs it as an admin user; P9.5's deploy
 *       script runs it after every migrate (new tables need their grant).
 *   php artisan audit:grants --check   connects as the configured (application) user and fails
 *       (exit 1) when that user can UPDATE or DELETE audit_logs, or when the append-only triggers
 *       are missing. The deploy smoke test runs it in production.
 *
 * It never changes grants itself: the application user must not be able to.
 */
final class AuditGrantsCommand extends Command
{
    protected $signature = 'audit:grants
        {--check : fail when the connected user can UPDATE / DELETE audit_logs or the triggers are missing}
        {--user= : the application database user (script mode)}
        {--host=% : that user\'s host part (script mode)}';

    protected $description = 'Print or check the append-only database grants for audit_logs';

    public function handle(): int
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('audit:grants works on MySQL / MariaDB only.');

            return self::FAILURE;
        }

        return $this->option('check') ? $this->check() : $this->script();
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

    private function check(): int
    {
        $lines = array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select('SHOW GRANTS FOR CURRENT_USER()'));
        $violations = AuditGrants::violations(array_values($lines), DB::getDatabaseName());

        $triggers = array_map(fn (object $row): string => (string) ((array) $row)['Trigger'], DB::select("SHOW TRIGGERS WHERE `Table` = 'audit_logs'"));
        $missing = array_values(array_diff(['audit_logs_no_update', 'audit_logs_no_delete'], $triggers));

        foreach ($violations as $line) {
            $this->error('Can change audit_logs: '.$line);
        }
        foreach ($missing as $trigger) {
            $this->error('Missing trigger: '.$trigger);
        }

        if ($violations !== [] || $missing !== []) {
            $this->line('Fix: php artisan audit:grants --user=<app user> and run the output as a database admin.');

            return self::FAILURE;
        }

        $this->info('audit_logs is append-only for this user (SELECT / INSERT), and both triggers are in place.');

        return self::SUCCESS;
    }
}
