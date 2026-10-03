<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use Database\Factories\SubscriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A membership period for a profile (M10). Created by Billing (P5: ActivateSubscription, A06
 * complimentary grants) — never from input, so nothing is mass-assignable.
 *
 * @property string $id
 * @property string $profile_id
 * @property string $plan_id
 * @property SubscriptionStatus $status
 * @property SubscriptionSource $source
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $paused_at paused while the member is suspended (A03)
 */
final class Subscription extends Model
{
    /** @use HasFactory<SubscriptionFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'source' => SubscriptionSource::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * ACTIVE and covering the instant $at.
     *
     * @param  Builder<self>  $query
     */
    public function scopeCurrentAt(Builder $query, Carbon $at): void
    {
        $query->where('status', SubscriptionStatus::Active->value)
            ->whereNull('paused_at')                      // paused while the member is suspended (A03)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at);
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
