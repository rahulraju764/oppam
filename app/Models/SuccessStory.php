<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A couple's story (M12, M16, A08). Publishing needs both parties' consent, checked when it goes
 * live and again on every public read (scopeLive). A withdrawn story leaves the site at once.
 *
 * @property string $slug
 * @property string $couple
 * @property string $place
 * @property CarbonImmutable $wedding_date
 * @property string $quote
 * @property string $body sanitised HTML
 * @property int|null $image_width
 * @property int|null $image_height
 * @property CarbonImmutable|null $consent_a_at
 * @property CarbonImmutable|null $consent_b_at
 * @property string|null $consent_note
 * @property CarbonImmutable|null $withdrawn_at
 * @property string|null $withdrawn_reason
 */
final class SuccessStory extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\SuccessStoryFactory> */
    use HasFactory;

    protected $table = 'success_stories';

    public function contentType(): ContentType
    {
        return ContentType::Story;
    }

    public function title(): string
    {
        return $this->couple;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'wedding_date' => 'immutable_date',
            'image_width' => 'integer',
            'image_height' => 'integer',
            'consent_a_at' => 'immutable_datetime',
            'consent_b_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeLive(Builder $query): void
    {
        parent::scopeLive($query);
        $query->whereNotNull('consent_a_at')->whereNotNull('consent_b_at')->whereNull('withdrawn_at');
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    public function hasBothConsents(): bool
    {
        return $this->consent_a_at !== null && $this->consent_b_at !== null;
    }

    public function publishBlocker(): ?string
    {
        if ($this->isWithdrawn()) {
            return __('This story was withdrawn. Restore it and record fresh consent first.');
        }
        if (! $this->hasBothConsents()) {
            return __('Both partners must consent before this story can be published.');
        }

        return parent::publishBlocker();
    }
}
