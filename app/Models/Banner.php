<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A slide of the home hero carousel (M12, A08).
 *
 * @property string $heading
 * @property string $copy
 * @property string|null $alt
 * @property string|null $cta_label
 * @property string|null $cta_url
 * @property int|null $image_width
 * @property int|null $image_height
 */
final class Banner extends EditorialContent
{
    /** @use HasFactory<\Database\Factories\BannerFactory> */
    use HasFactory;

    public function contentType(): ContentType
    {
        return ContentType::Banner;
    }

    public function title(): string
    {
        return $this->heading;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + ['image_width' => 'integer', 'image_height' => 'integer'];
    }
}
