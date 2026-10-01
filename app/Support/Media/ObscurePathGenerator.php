<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\MediaLibrary\Support\PathGenerator\PathGenerator;

/**
 * Media paths keyed by the random media uuid (M11), not the auto-increment id the default
 * generator uses: public conversion URLs can't be enumerated, so a viewer who was only given the
 * blurred URL can't guess the clear one. Layout: {uuid}/original, {uuid}/c/{conversion},
 * {uuid}/r/{responsive}.
 */
final class ObscurePathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $this->base($media);
    }

    public function getPathForConversions(Media $media): string
    {
        return $this->base($media).'c/';
    }

    public function getPathForResponsiveImages(Media $media): string
    {
        return $this->base($media).'r/';
    }

    private function base(Media $media): string
    {
        return $media->uuid.'/';
    }
}
