<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AnnouncementAudience;
use Carbon\CarbonImmutable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A14 announcement (P8.3a): a site-wide banner for members ("Onam offer: 20 % off Gold"), shown
 * inside its window to its audience until dismissed (if dismissible) or ended early. URLs and live
 * payloads use `code` (ANN…), never the id.
 *
 * @property string $id
 * @property string $code
 * @property string $title
 * @property string $body
 * @property string|null $action_url
 * @property string|null $action_text
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property AnnouncementAudience $audience
 * @property bool $is_dismissible
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable|null $broadcast_at
 * @property CarbonImmutable|null $end_signalled_at
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AdminUser|null $creator
 */
final class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'audience' => AnnouncementAudience::class,
            'is_dismissible' => 'boolean',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'broadcast_at' => 'immutable_datetime',
            'end_signalled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * Inside its window and not ended early.
     *
     * @param  Builder<self>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $now = now();
        $query->whereNull('ended_at')
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    public function isLive(): bool
    {
        return $this->ended_at === null
            && ($this->starts_at === null || ! $this->starts_at->isFuture())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function isUpcoming(): bool
    {
        return $this->ended_at === null && $this->starts_at !== null && $this->starts_at->isFuture();
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }
}
