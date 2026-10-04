<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\MatchScoreFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $source_profile_id
 * @property string $target_profile_id
 * @property int $score
 * @property int $preference_fit
 * @property int $reverse_fit
 * @property int $activity_score
 * @property int $completeness_score
 * @property \Illuminate\Support\Carbon $calculated_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Profile|null $sourceProfile
 * @property-read Profile|null $targetProfile
 */
final class MatchScore extends Model
{
    /** @use HasFactory<MatchScoreFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'source_profile_id',
        'target_profile_id',
        'score',
        'preference_fit',
        'reverse_fit',
        'activity_score',
        'completeness_score',
        'calculated_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'score' => 'integer',
        'preference_fit' => 'integer',
        'reverse_fit' => 'integer',
        'activity_score' => 'integer',
        'completeness_score' => 'integer',
        'calculated_at' => 'datetime',
    ];

    /** @return BelongsTo<Profile, $this> */
    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'source_profile_id');
    }

    /** @return BelongsTo<Profile, $this> */
    public function targetProfile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'target_profile_id');
    }

    protected static function newFactory(): MatchScoreFactory
    {
        return MatchScoreFactory::new();
    }
}
