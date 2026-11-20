<?php

declare(strict_types=1);

use App\Enums\AuditActorType;
use App\Models\AuditLog;
use Illuminate\Support\Str;

/*
| A12 audit test helpers (P9.2a). Loaded from tests/Pest.php.
*/

/**
 * An audit row written directly (bypassing AuditLogger's redaction, so tests can prove the viewer
 * redacts again). System actor unless given.
 *
 * @param  array<string, mixed>  $attributes
 */
function auditRow(array $attributes = []): AuditLog
{
    $row = new AuditLog;
    $row->forceFill([
        'id' => (string) Str::ulid(),
        'actor_type' => AuditActorType::System,
        'action' => 'test.done',
        ...$attributes,
    ])->save();

    return $row;
}
