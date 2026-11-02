<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A quote on the home page (M12, A08). A real person's words and photo, so consent is required.
 *
 * @property string $name
 * @property string $place
 * @property string $quote
 * @property int|null $image_width
 * @property int|null $image_height
 * @property CarbonImmutable|null $consent_at
 */
final class Testimonial extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\TestimonialFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Testimonial;
    }

    public function title(): string
    {
        return $this->name;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'image_width' => 'integer',
            'image_height' => 'integer',
            'consent_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeLive(Builder $query): void
    {
        parent::scopeLive($query);
        $query->whereNotNull('consent_at');
    }

    public function publishBlocker(): ?string
    {
        return $this->consent_at === null
            ? __('This person must consent before the testimonial can be published.')
            : parent::publishBlocker();
    }
}
