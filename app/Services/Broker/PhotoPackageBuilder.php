<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Data\Broker\PhotoFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The bureau photo package (§11A B.7): the profile's APPROVED photos at original resolution, in a
 * zip written to a temporary file. Entry names are ours (OPM…-1.jpg), never the uploader's, so no
 * path can be smuggled in. The caller streams the file and deletes it after sending.
 */
final class PhotoPackageBuilder
{
    /**
     * @param  list<PhotoFile>  $photos
     * @return string path of the temporary zip
     */
    public function build(array $photos): string
    {
        $path = tempnam(sys_get_temp_dir(), 'oppam-photos-');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the photo package.');
        }

        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not open the photo package.');
        }

        foreach ($photos as $photo) {
            $bytes = Storage::disk($photo->disk)->get($photo->path);
            if (is_string($bytes)) {
                $zip->addFromString($photo->name, $bytes);
            }
        }

        $zip->close();

        return $path;
    }
}
