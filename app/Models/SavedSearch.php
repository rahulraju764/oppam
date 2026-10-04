<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Search\SearchCriteria;
use App\Enums\AlertFrequency;
use Database\Factories\SavedSearchFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $profile_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property AlertFrequency $alert_frequency
 * @property \Illuminate\Support\Carbon|null $last_alerted_at
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read Profile|null $profile
 */
final class SavedSearch extends Model
{
    /** @use HasFactory<SavedSearchFactory> */
    use HasFactory, HasUlids;

    public const MAX_PER_PROFILE = 10;

    /** @var list<string> */
    protected $fillable = [
        'profile_id',
        'name',
        'filters',
        'alert_frequency',
        'last_alerted_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'filters' => 'array',
        'alert_frequency' => AlertFrequency::class,
        'last_alerted_at' => 'datetime',
    ];

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromInput($this->filters ?? []);
    }

    protected static function newFactory(): SavedSearchFactory
    {
        return SavedSearchFactory::new();
    }
}
