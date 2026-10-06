<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EngagementSignalType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An abuse signal raised by an engagement guard (like spam, R-M06-2) for the A13 monitors (P4.3).
 * Append-only: rows are written by the guards and never changed.
 *
 * @property string $id
 * @property string $profile_id
 * @property EngagementSignalType $type
 * @property array<string, int|string>|null $details
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class EngagementSignal extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['type' => EngagementSignalType::class, 'details' => 'array'];
    }
}
