<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use App\Models\Masters\Caste;
use App\Models\Masters\Religion;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A community SEO landing page (M12, A08), e.g. /christian-matrimony-kerala. Only a published
 * page has a URL and a sitemap entry. It never lists profiles: counts and sign-up only.
 *
 * @property string $slug
 * @property string $heading
 * @property string $intro sanitised HTML
 * @property string $meta_description
 * @property int|null $religion_id
 * @property int|null $caste_id
 * @property-read Religion|null $religion
 * @property-read Caste|null $caste
 */
final class CommunityPage extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\CommunityPageFactory> */
    use HasFactory;

    /** The URL shape every community page follows, so the route can't swallow other pages. */
    public const SLUG_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*-matrimony(?:-[a-z0-9]+)*';

    public function contentType(): ContentType
    {
        return ContentType::Community;
    }

    public function title(): string
    {
        return $this->heading;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['religion_id' => 'integer', 'caste_id' => 'integer'];
    }

    /** @return BelongsTo<Religion, $this> */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /** @return BelongsTo<Caste, $this> */
    public function caste(): BelongsTo
    {
        return $this->belongsTo(Caste::class);
    }
}
