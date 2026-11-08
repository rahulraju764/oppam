<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClosureReason;
use Carbon\CarbonImmutable;
use Database\Factories\ProfileClosureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A member closing their own account (M14, P8.2c). Written by CloseAccount; restored_at by
 * RestoreClosedAccount. The feedback is the member's own words — never shown to other members,
 * cleared by the purge (PurgeDeletedMember).
 *
 * @property string $id
 * @property string $user_id
 * @property string|null $profile_id
 * @property ClosureReason $reason
 * @property string|null $feedback
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $restored_at
 * @property-read User $user
 */
final class ProfileClosure extends Model
{
    /** @use HasFactory<ProfileClosureFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason' => ClosureReason::class,
            'created_at' => 'immutable_datetime',
            'restored_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
