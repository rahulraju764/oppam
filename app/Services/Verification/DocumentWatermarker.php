<?php

declare(strict_types=1);

namespace App\Services\Verification;

use GdImage;
use RuntimeException;

/**
 * Burns the A05 viewer watermark ("admin email + time", "access is logged") into an ID image
 * before it leaves the server: a diagonal, repeated, semi-transparent line across the whole
 * picture, so a screenshot still says who looked and when. Output is a fresh JPEG. (PDF pages are
 * drawn by pdf.js in the viewer, which paints the same text over each page.)
 */
final class DocumentWatermarker
{
    /** @throws RuntimeException when the bytes are not a readable image */
    public function apply(string $imageBytes, string $text): string
    {
        $image = @imagecreatefromstring($imageBytes);

        if (! $image instanceof GdImage) {
            throw new RuntimeException('The document image could not be read.');
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $font = (string) config('oppam.media.watermark_font');
        $size = max(10, (int) round(min($width, $height) / 32));
        $ink = (int) imagecolorallocatealpha($image, 224, 35, 73, 80);      // brand red, ~40 % opaque
        $shadow = (int) imagecolorallocatealpha($image, 255, 255, 255, 90);

        // Diagonal rows, spaced so every part of the card carries the text.
        $step = $size * 7;
        for ($y = -$height; $y < $height * 2; $y += $step) {
            for ($x = -$width; $x < $width * 2; $x += $size * (mb_strlen($text) + 6)) {
                imagettftext($image, $size, 30, $x + 1, $y + 1, $shadow, $font, $text);
                imagettftext($image, $size, 30, $x, $y, $ink, $font, $text);
            }
        }

        ob_start();
        imagejpeg($image, null, 85);
        $out = (string) ob_get_clean();
        imagedestroy($image);

        return $out;
    }
}
