<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One saved version of an A14 template (P8.3a). Append-only: a change is a new version (SaveTemplate),
 * a revert copies an old one forward — a saved version is never edited or deleted.
 *
 * @property string $id
 * @property string $template_id
 * @property int $version
 * @property string|null $subject
 * @property string $body
 * @property string|null $dlt_template_id
 * @property string|null $created_by_admin_id
 * @property CarbonImmutable $created_at
 * @property-read AdminUser|null $author
 */
final class CommunicationTemplateVersion extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Template versions are append-only.'));
        self::deleting(fn (): never => throw new LogicException('Template versions are append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }
}
