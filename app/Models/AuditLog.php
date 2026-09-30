<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditActorType;
use App\Exceptions\Audit\AuditLogIsImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An append-only audit entry (PRD A12). Written only by AuditLogger::record(). Updating or
 * deleting a row throws here, and database triggers refuse it too.
 *
 * @property string $id
 * @property AuditActorType $actor_type
 * @property string|null $actor_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $subject_label
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property string|null $reason
 * @property Carbon $created_at
 */
final class AuditLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::updating(fn (): never => throw AuditLogIsImmutable::update());
        self::deleting(fn (): never => throw AuditLogIsImmutable::delete());
    }
}
