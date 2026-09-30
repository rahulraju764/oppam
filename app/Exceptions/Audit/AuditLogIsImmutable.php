<?php

declare(strict_types=1);

namespace App\Exceptions\Audit;

use LogicException;

/** audit_logs is append-only (PRD A12): an entry can never be changed or removed. */
final class AuditLogIsImmutable extends LogicException
{
    public static function update(): self
    {
        return new self('Audit log entries are append-only and cannot be updated.');
    }

    public static function delete(): self
    {
        return new self('Audit log entries are append-only and cannot be deleted.');
    }
}
