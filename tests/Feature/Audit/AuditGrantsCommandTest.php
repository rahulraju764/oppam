<?php

declare(strict_types=1);

use App\Console\Commands\AuditGrantsCommand;
use App\Contracts\AuditPrivileges;
use Illuminate\Support\Facades\DB;

/*
| P9.2a — `audit:grants` (PRD A12 "append-only at model and DB grant level"): prints the per-table
| grant script for the application user; --check fails while the connected user can change
| audit_logs; --triggers fails while an append-only trigger is missing. The test database user is
| root, so the least-privilege production app user is simulated with a fake AuditPrivileges.
*/

/** @param  list<string>  $grants @param  list<string>  $triggers */
function fakeAuditPrivileges(array $grants, array $triggers): void
{
    app()->instance(AuditPrivileges::class, new class($grants, $triggers) implements AuditPrivileges
    {
        /** @param  list<string>  $grantLines @param  list<string>  $triggerNames */
        public function __construct(private array $grantLines, private array $triggerNames) {}

        public function grants(?string $connection = null): array
        {
            return $this->grantLines;
        }

        public function auditTriggers(?string $connection = null): array
        {
            return $this->triggerNames;
        }
    });
}

/** SHOW GRANTS as MySQL prints it for a user set up by the generated script. */
function leastPrivilegeGrants(): array
{
    $db = str_replace('_', '\_', DB::getDatabaseName());

    return [
        'GRANT USAGE ON *.* TO `oppam_app`@`10.0.%`',
        "GRANT SELECT, INSERT ON `{$db}`.`audit_logs` TO `oppam_app`@`10.0.%`",
        "GRANT SELECT, INSERT, UPDATE, DELETE ON `{$db}`.`profiles` TO `oppam_app`@`10.0.%`",
    ];
}

it('A12 audit:grants needs --user and prints one grant per table, audit_logs SELECT / INSERT only', function (): void {
    $this->artisan('audit:grants')->expectsOutputToContain('Pass --user')->assertFailed();

    $this->artisan('audit:grants', ['--user' => 'oppam_app', '--host' => '10.0.%'])
        ->expectsOutputToContain("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'oppam_app'@'10.0.%';")
        ->expectsOutputToContain('GRANT SELECT, INSERT ON `'.DB::getDatabaseName()."`.`audit_logs` TO 'oppam_app'@'10.0.%';")
        ->expectsOutputToContain('GRANT SELECT, INSERT, UPDATE, DELETE ON `'.DB::getDatabaseName()."`.`profiles` TO 'oppam_app'@'10.0.%';")
        ->assertSuccessful();
});

it('A12 audit:grants --check fails for a user that can UPDATE / DELETE audit_logs (the real test root user)', function (): void {
    $this->artisan('audit:grants', ['--check' => true])
        ->expectsOutputToContain('Can change audit_logs: GRANT ALL PRIVILEGES')
        ->assertFailed();
});

it('A12 audit:grants --check passes for the least-privilege app user, which cannot see the triggers', function (): void {
    fakeAuditPrivileges(leastPrivilegeGrants(), triggers: []);

    $this->artisan('audit:grants', ['--check' => true])
        ->expectsOutputToContain('append-only for this user')
        ->expectsOutputToContain('--triggers')
        ->doesntExpectOutputToContain('Missing trigger')
        ->assertSuccessful();
});

it('A12 audit:grants --check fails when the app user also holds TRIGGER on audit_logs', function (): void {
    $db = str_replace('_', '\_', DB::getDatabaseName());
    fakeAuditPrivileges([...leastPrivilegeGrants(), "GRANT TRIGGER ON `{$db}`.`audit_logs` TO `oppam_app`@`10.0.%`"], AuditGrantsCommand::TRIGGERS);

    $this->artisan('audit:grants', ['--check' => true])
        ->expectsOutputToContain('Can change audit_logs: GRANT TRIGGER')
        ->assertFailed();
});

it('A12 audit:grants --triggers finds both triggers for a user that can see them (the real test root user)', function (): void {
    $this->artisan('audit:grants', ['--triggers' => true])
        ->expectsOutputToContain('Both append-only triggers are in place')
        ->assertSuccessful();
});

it('A12 audit:grants --triggers fails when a trigger is missing, and says why it may be invisible', function (): void {
    fakeAuditPrivileges([], ['audit_logs_no_update']);

    $this->artisan('audit:grants', ['--triggers' => true])
        ->expectsOutputToContain('Missing trigger: audit_logs_no_delete')
        ->doesntExpectOutputToContain('Missing trigger: audit_logs_no_update')
        ->expectsOutputToContain('TRIGGER privilege')
        ->assertFailed();
});

it('A12 audit:grants refuses an unknown --connection', function (): void {
    $this->artisan('audit:grants', ['--check' => true, '--connection' => 'nope'])
        ->expectsOutputToContain('Unknown database connection: nope')
        ->assertFailed();
});
