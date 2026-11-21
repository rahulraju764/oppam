<?php

declare(strict_types=1);

use App\Domain\Audit\AuditGrants;

/*
| P9.2a — A12 "append-only at model and DB grant level": the grant script for the application
| user, and the check that reads SHOW GRANTS (no database here: pure rules).
*/

it('A12 grant script: per-table grants, audit_logs SELECT / INSERT only, everything revoked first', function (): void {
    $sql = AuditGrants::script('oppam', 'oppam_app', '10.0.%', ['audit_logs', 'profiles', 'orders']);

    expect($sql[0])->toBe("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'oppam_app'@'10.0.%';")
        ->and($sql)->toContain("GRANT SELECT, INSERT ON `oppam`.`audit_logs` TO 'oppam_app'@'10.0.%';")
        ->and($sql)->toContain("GRANT SELECT, INSERT, UPDATE, DELETE ON `oppam`.`profiles` TO 'oppam_app'@'10.0.%';")
        ->and($sql)->toHaveCount(4)
        ->and(implode("\n", $sql))->not->toContain('`oppam`.*');   // never a database-wide grant
});

it('A12 grant script: quotes names so a hostile user / table name cannot break out', function (): void {
    $sql = AuditGrants::script('db`x', "a'b", '%', ['t`1']);

    expect($sql[0])->toBe("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'a\\'b'@'%';")
        ->and($sql[1])->toBe("GRANT SELECT, INSERT, UPDATE, DELETE ON `db``x`.`t``1` TO 'a\\'b'@'%';");
});

it('A12 grant check: flags every grant that lets the user change or wipe audit_logs', function (string $grant): void {
    expect(AuditGrants::violations([$grant], 'oppam_db'))->toBe([$grant]);
})->with([
    'all on everything' => 'GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` WITH GRANT OPTION',
    'update database-wide' => 'GRANT SELECT, INSERT, UPDATE ON `oppam_db`.* TO `app`@`%`',
    'delete on the table' => 'GRANT SELECT, DELETE ON `oppam_db`.`audit_logs` TO `app`@`%`',
    'column update' => 'GRANT SELECT, UPDATE (reason) ON `oppam_db`.`audit_logs` TO `app`@`%`',
    'escaped db name' => 'GRANT ALL PRIVILEGES ON `oppam\_db`.* TO `app`@`%`',
    'lower case' => 'grant select, delete on `oppam_db`.`audit_logs` to `app`@`%`',
    // TRUNCATE TABLE needs only DROP and fires no trigger; ALTER ... ENGINE=BLACKHOLE discards rows;
    // TRIGGER lets the user drop the no-update / no-delete triggers.
    'drop database-wide (truncate)' => 'GRANT SELECT, INSERT, DROP ON `oppam_db`.* TO `app`@`%`',
    'alter database-wide' => 'GRANT SELECT, INSERT, ALTER ON `oppam_db`.* TO `app`@`%`',
    'trigger database-wide' => 'GRANT SELECT, INSERT, TRIGGER ON `oppam_db`.* TO `app`@`%`',
    'drop on the table' => 'GRANT SELECT, INSERT, DROP ON `oppam_db`.`audit_logs` TO `app`@`%`',
    'migration-style DDL grant' => 'GRANT CREATE, DROP, ALTER, INDEX ON `oppam\_db`.* TO `app`@`%`',
    'wildcard database pattern' => 'GRANT ALL PRIVILEGES ON `oppam%`.* TO `app`@`%`',
    'single-char wildcard' => 'GRANT DELETE ON `oppam_d_`.* TO `app`@`%`',
]);

it('A12 grant check: a literal "\_" in the pattern does not match another character', function (): void {
    expect(AuditGrants::violations(['GRANT ALL PRIVILEGES ON `oppam\_db`.* TO `app`@`%`'], 'oppamxdb'))->toBe([])
        ->and(AuditGrants::violations(['GRANT ALL PRIVILEGES ON `other%`.* TO `app`@`%`'], 'oppam_db'))->toBe([]);
});

it('A12 grant check: an append-only setup has no violation', function (): void {
    expect(AuditGrants::violations([
        'GRANT USAGE ON *.* TO `app`@`%`',
        'GRANT SELECT, INSERT ON `oppam_db`.`audit_logs` TO `app`@`%`',
        'GRANT SELECT, INSERT, UPDATE, DELETE ON `oppam_db`.`profiles` TO `app`@`%`',
        'GRANT DROP, ALTER ON `oppam_db`.`profiles` TO `app`@`%`',
        'GRANT ALL PRIVILEGES ON `other_db`.* TO `app`@`%`',
        'GRANT PROXY ON ``@`` TO `app`@`%`',
    ], 'oppam_db'))->toBe([]);
});
