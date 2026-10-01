<?php

declare(strict_types=1);

namespace App\Services\Media;

use GdImage;
use InvalidArgumentException;

/**
 * Re-encodes an uploaded photo before it is stored (M11): decodes it with GD (so a renamed
 * script or SVG is refused), applies the EXIF orientation, and writes a fresh JPEG — which
 * drops every EXIF / GPS tag from the original too, not only from the conversions.
 */
final class ImageSanitizer
{
    /**
     * @return array{path: string, width: int, height: int} a new temporary JPEG
     *
     * @throws InvalidArgumentException when the file is not a decodable raster image
     */
    public function sanitize(string $path): array
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Not a JPG, PNG or WebP image.');
        }

        $contents = @file_get_contents($path);
        $image = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The image could not be read.');
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $path);
        }

        // Flatten transparency onto white (JPEG has no alpha).
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        $target = tempnam(sys_get_temp_dir(), 'opm-photo-').'.jpg';
        imagejpeg($canvas, $target, 90);

        $result = ['path' => $target, 'width' => imagesx($canvas), 'height' => imagesy($canvas)];
        imagedestroy($image);
        imagedestroy($canvas);

        return $result;
    }

    private function orient(GdImage $image, string $path): GdImage
    {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
