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
use Illuminate\Support\Str;

/**
 * A member's saved search (M04, max MAX_PER_PROFILE): `filters` holds SearchCriteria::toInput()
 * (normalised, never raw input). `alert_token` is the secret in the alert email's unsubscribe link;
 * it is set here on create and never mass-assigned or shown.
 *
 * @property string $id
 * @property string $profile_id
 * @property string $name
 * @property array<string, mixed> $filters
 * @property AlertFrequency $alert_frequency
 * @property string $alert_token
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

    public const NAME_MAX = 60;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'filters',
        'alert_frequency',
        'last_alerted_at',
    ];

    /** @var list<string> */
    protected $hidden = ['alert_token'];

    /** @var array<string, string> */
    protected $casts = [
        'filters' => 'array',
        'alert_frequency' => AlertFrequency::class,
        'last_alerted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $search): void {
            $search->alert_token = Str::random(48);
        });
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function criteria(): SearchCriteria
    {
        return SearchCriteria::fromInput($this->filters ?? []);
    }

    /** The search page with these filters (re-normalised, so an old row can't carry junk). */
    public function searchUrl(): string
    {
        return route('member.search', ['f' => $this->criteria()->toInput()]);
    }

    protected static function newFactory(): SavedSearchFactory
    {
        return SavedSearchFactory::new();
    }
}
