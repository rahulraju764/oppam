<?php

declare(strict_types=1);

namespace App\Support\Media;

use Spatie\MediaLibrary\Conversions\Conversion;
use Spatie\MediaLibrary\Support\FileNamer\FileNamer;

/**
 * Conversion file names that can't be derived from one another (M11). The default namer gives
 * every version the same stem plus "-blurred" / "-full" / …, so a viewer handed only the blurred
 * URL could read the clear photo by editing the suffix (P1.4 review Blocker). Here each name is
 * an HMAC of the stored file name and the conversion, keyed with the app key: knowing one
 * version's URL reveals nothing about another's.
 */
final class KeyedFileNamer extends FileNamer
{
    public function conversionFileName(string $fileName, Conversion $conversion): string
    {
        return $this->keyed(pathinfo($fileName, PATHINFO_FILENAME).'|'.$conversion->getName());
    }

    public function responsiveFileName(string $fileName): string
    {
        return $this->keyed(pathinfo($fileName, PATHINFO_FILENAME).'|responsive');
    }

    private function keyed(string $value): string
    {
        return substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 40);
    }
}
