<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * Database-level protection of the audit trail (PRD A12 "append-only at model and DB grant
 * level"; P9.2a). Pure rules, no DB access:
 *
 *  - script(): the statements a DBA runs for the APPLICATION user. MySQL cannot take one table out
 *    of a database-wide grant (a REVOKE on a table grant that was given at database level fails),
 *    so the app user gets per-table rights: SELECT / INSERT / UPDATE / DELETE on every table but
 *    audit_logs, and SELECT / INSERT only on audit_logs. Migrations run as a separate migration
 *    user (DDL rights), and the script is re-run after each deploy's migrate so new tables get
 *    their grant (P9.5 deploy script).
 *  - violations(): reads SHOW GRANTS lines and lists every grant that would let this user UPDATE or
 *    DELETE audit_logs (global, database-wide or on the table itself; ALL PRIVILEGES included).
 */
final class AuditGrants
{
    public const TABLE = 'audit_logs';

    /**
     * @param  list<string>  $tables  every table in the database
     * @return list<string>
     */
    public static function script(string $database, string $user, string $host, array $tables): array
    {
        $who = self::quoteString($user).'@'.self::quoteString($host);
        $db = self::quoteIdentifier($database);
        $statements = ["REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$who};"];

        foreach ($tables as $table) {
            $privileges = $table === self::TABLE ? 'SELECT, INSERT' : 'SELECT, INSERT, UPDATE, DELETE';
            $statements[] = "GRANT {$privileges} ON {$db}.".self::quoteIdentifier($table)." TO {$who};";
        }

        return $statements;
    }

    /**
     * @param  list<string>  $grantLines  the rows of SHOW GRANTS FOR CURRENT_USER()
     * @return list<string> the offending grant lines ([] = audit_logs is append-only for this user)
     */
    public static function violations(array $grantLines, string $database): array
    {
        $offending = [];

        foreach ($grantLines as $line) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+(\S+)\s+TO\s/i', trim($line), $m) !== 1) {
                continue;   // GRANT PROXY / role grants carry no table privileges
            }
            if (! self::covers($m[2], $database)) {
                continue;
            }
            $privileges = array_map(fn (string $p): string => strtoupper(trim($p)), explode(',', $m[1]));
            foreach ($privileges as $privilege) {
                if (in_array($privilege, ['ALL', 'ALL PRIVILEGES', 'UPDATE', 'DELETE'], true) || str_starts_with($privilege, 'UPDATE (')) {
                    $offending[] = trim($line);
                    break;
                }
            }
        }

        return $offending;
    }

    /** Does a grant scope (`*.*`, `db`.*, `db`.`audit_logs`) include the audit table? */
    private static function covers(string $scope, string $database): bool
    {
        $scope = str_replace('`', '', $scope);
        [$db, $table] = array_pad(explode('.', $scope, 2), 2, '*');
        $db = str_replace('\\_', '_', $db);   // SHOW GRANTS escapes "_" in database names

        return ($db === '*' || strcasecmp($db, $database) === 0) && ($table === '*' || strcasecmp($table, self::TABLE) === 0);
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`'.str_replace('`', '``', $name).'`';
    }

    private static function quoteString(string $value): string
    {
        return "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $value)."'";
    }
}
