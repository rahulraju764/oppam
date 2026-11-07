<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdAudience;
use App\Enums\AdSlot;
use App\Enums\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * An ad or promo in the member right rail (A08 ad slots, template ads.php): audience, a running
 * window and a rotation weight. Only PUBLISHED creatives inside their window are shown (AdRotation).
 *
 * @property AdSlot $slot
 * @property AdAudience $audience
 * @property int $weight 1–100, the share of views among creatives competing for the same slot
 * @property CarbonImmutable|null $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property string $heading
 * @property string|null $copy
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property string|null $alt
 * @property int|null $image_width
 * @property int|null $image_height
 */
final class AdCreative extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\AdCreativeFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Ad;
    }

    public function title(): string
    {
        return $this->heading;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'slot' => AdSlot::class,
            'audience' => AdAudience::class,
            'weight' => 'integer',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'image_width' => 'integer',
            'image_height' => 'integer',
        ];
    }

    /**
     * Inside its running window now.
     *
     * @param  Builder<static>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $now = now();
        $query->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now));
    }

    /** A promo card is text only; a banner or carousel slide needs its image. */
    public function publishBlocker(): ?string
    {
        $row = $this->withDraft();
        if ($row->slot->needsImage() && $row->image_path === null) {
            return __('Add an image before publishing.');
        }
        if ($row->ends_at !== null && $row->ends_at->isPast()) {
            return __('This ad’s end date has passed. Change the dates first.');
        }

        return null;
    }
}
