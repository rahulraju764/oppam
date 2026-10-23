<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\BlockFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One profile blocking another (PRD §7.2). Symmetric in effect: neither side sees the other
 * (R-M03-1, App\Domain\Safety\BlockList). Created by BlockProfile, removed by UnblockProfile only.
 *
 * @property string $id
 * @property string $blocker_profile_id
 * @property string $blocked_profile_id
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Profile|null $blocker
 * @property-read Profile|null $blocked
 */
final class Block extends Model
{
    /** @use HasFactory<BlockFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Profile, $this> */
    public function blocker(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'blocker_profile_id')->withTrashed();
    }

    /** @return BelongsTo<Profile, $this> */
    public function blocked(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'blocked_profile_id')->withTrashed();
    }
}
