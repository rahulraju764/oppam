<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Contracts\AuditPrivileges;
use App\Domain\Audit\AuditGrants;
use Illuminate\Support\Facades\DB;

/** MySQL / MariaDB reader for `audit:grants` (P9.2a). Read-only: it never changes a grant. */
final class MysqlAuditPrivileges implements AuditPrivileges
{
    public function grants(?string $connection = null): array
    {
        return array_values(array_map(
            fn (object $row): string => (string) array_values((array) $row)[0],
            DB::connection($connection)->select('SHOW GRANTS FOR CURRENT_USER()'),
        ));
    }

    public function auditTriggers(?string $connection = null): array
    {
        return array_values(array_map(
            fn (object $row): string => (string) ((array) $row)['Trigger'],
            DB::connection($connection)->select("SHOW TRIGGERS WHERE `Table` = '".AuditGrants::TABLE."'"),
        ));
    }
}
