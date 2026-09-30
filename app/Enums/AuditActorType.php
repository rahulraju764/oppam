<?php

declare(strict_types=1);

namespace App\Enums;

/** Who performed an audited action (A12). */
enum AuditActorType: string
{
    case Admin = 'ADMIN';
    case User = 'USER';
    case System = 'SYSTEM';
}
