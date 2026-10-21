<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MemberRestrictionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A moderator's pause on everything a member sends (PRD A13 one-click "rate-limit", decisions
 * 2026-10-18): likes, favourites, interests, photo / horoscope requests and chat messages are refused
 * until `until`, unless lifted earlier. Checked by EngagementRestrictions.
 *
 * @property string $id
 * @property string $profile_id
 * @property \Illuminate\Support\Carbon $until
 * @property string|null $reason
 * @property string|null $signal_id
 * @property string|null $created_by_admin_id
 * @property \Illuminate\Support\Carbon|null $lifted_at
 * @property string|null $lifted_by_admin_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Profile|null $profile
 * @property-read AdminUser|null $createdBy
 */
final class MemberRestriction extends Model
{
    /** @use HasFactory<MemberRestrictionFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /**
     * Pauses still running.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lifted_at')->where('until', '>', now());
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by_admin_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['until' => 'datetime', 'lifted_at' => 'datetime'];
    }
}
