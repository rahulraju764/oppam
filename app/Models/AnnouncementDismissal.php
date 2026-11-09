<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A member closed an announcement banner (A14, P8.3a). One row per member and announcement.
 *
 * @property int $id
 * @property string $announcement_id
 * @property string $user_id
 * @property CarbonImmutable $created_at
 */
final class AnnouncementDismissal extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'immutable_datetime'];
    }
}
