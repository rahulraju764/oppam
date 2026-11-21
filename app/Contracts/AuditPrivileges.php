<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * What a database user can do to audit_logs (PRD A12, P9.2a `audit:grants`). Behind an interface
 * so the least-privilege case (the real production app user) can be tested: the test database
 * user is root.
 */
interface AuditPrivileges
{
    /**
     * The rows of SHOW GRANTS FOR CURRENT_USER() on this connection.
     *
     * @return list<string>
     */
    public function grants(?string $connection = null): array;

    /**
     * The names of the triggers on audit_logs that this connection's user can see. MySQL / MariaDB
     * only show a table's triggers to a user with the TRIGGER privilege on it, so the application
     * user (correctly) sees none — run this as the migration / database-admin user.
     *
     * @return list<string>
     */
    public function auditTriggers(?string $connection = null): array;
}
