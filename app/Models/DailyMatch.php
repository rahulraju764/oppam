<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\DailyMatchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Daily match recommendation (PRD §10 M05, §12 F06).
 * Generated daily at 05:00 IST; expires at 23:59:59 IST.
 *
 * @property string $id
 * @property string $profile_id
 * @property string $matched_profile_id
 * @property CarbonImmutable $match_date
 * @property int $score
 * @property bool $is_viewed
 * @property bool $is_interacted
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Profile|null $profile
 * @property-read Profile|null $matchedProfile
 */
final class DailyMatch extends Model
{
    /** @use HasFactory<DailyMatchFactory> */
    use HasFactory, HasUlids;

    protected $table = 'daily_matches';

    /** @var list<string> */
    protected $fillable = [
        'profile_id',
        'matched_profile_id',
        'match_date',
        'score',
        'is_viewed',
        'is_interacted',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'match_date' => 'immutable_date',
            'score' => 'integer',
            'is_viewed' => 'boolean',
            'is_interacted' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function matchedProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'matched_profile_id');
    }
}
