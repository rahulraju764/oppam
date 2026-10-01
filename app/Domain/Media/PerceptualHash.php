<?php

declare(strict_types=1);

namespace App\Domain\Media;

use GdImage;
use InvalidArgumentException;

/**
 * 64-bit difference hash (dHash) of an image, as 16 hex characters (M11). Near-identical photos
 * (resized, re-compressed, lightly edited) get hashes a few bits apart, so A04 can flag the same
 * photo on two profiles (P1.6). Plain GD; no package needed.
 */
final class PerceptualHash
{
    public static function ofFile(string $path): string
    {
        $contents = @file_get_contents($path);
        $source = is_string($contents) ? @imagecreatefromstring($contents) : false;

        if (! $source instanceof GdImage) {
            throw new InvalidArgumentException('Not a readable image.');
        }

        // 9×8 greyscale: each row gives 8 "is the next pixel brighter?" bits.
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $source, 0, 0, 0, 0, 9, 8, imagesx($source), imagesy($source));
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits .= (self::brightness($small, $x, $y) > self::brightness($small, $x + 1, $y)) ? '1' : '0';
            }
        }

        imagedestroy($small);
        imagedestroy($source);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex((int) bindec($nibble));
        }

        return $hex;
    }

    /** Number of differing bits between two hashes (0 = same picture; ≤ 10 ≈ near-duplicate). */
    public static function distance(string $a, string $b): int
    {
        $distance = 0;
        foreach (str_split($a) as $i => $char) {
            $distance += substr_count(decbin(hexdec($char) ^ hexdec($b[$i] ?? '0')), '1');
        }

        return $distance;
    }

    private static function brightness(GdImage $image, int $x, int $y): int
    {
        return imagecolorat($image, $x, $y) & 0xFF;
    }
}
