<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * File safety for a bulk upload (§11A B.9 limits, B.17 "extension and MIME sniffed, size limits,
 * zip entries whitelisted by extension, no nested archives, zip-slip path checks"). Decides from
 * the bytes, never from the browser's name or type:
 *  - sheet: `.xlsx` must really be an Office Open XML workbook (a zip holding xl/workbook.xml),
 *    `.csv` must be UTF-8 text without NUL bytes; ≤ SHEET_MAX_KB;
 *  - photo zip (optional): a real zip ≤ ZIP_MAX_KB whose entries are all photos (jpg / jpeg / png /
 *    webp / heic) ≤ ENTRY_MAX_BYTES each, at most MAX_ENTRIES, with no nested archive and no path
 *    that could escape a folder (absolute, `..`, backslash or drive letter). The zip is only
 *    listed here — nothing is extracted before the import step (P7.7).
 */
final class ImportFileInspector
{
    public const SHEET_MAX_KB = 10240;

    public const ZIP_MAX_KB = 204800;

    public const ENTRY_MAX_BYTES = 10 * 1024 * 1024;

    public const MAX_ENTRIES = 2500;

    /** The declared unpacked size of all entries together (zip-bomb guard; real sizes are re-checked on extraction, P7.7). */
    public const TOTAL_MAX_BYTES = 1024 * 1024 * 1024;

    private const PHOTO_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'heic'];

    /**
     * @return string the sheet's real type: xlsx | csv
     *
     * @throws ValidationException key `sheet`
     */
    public function sheet(UploadedFile $file): string
    {
        $refuse = fn (string $why): ValidationException => ValidationException::withMessages(['sheet' => $why]);
        $extension = mb_strtolower($file->getClientOriginalExtension());

        if ($file->getSize() > self::SHEET_MAX_KB * 1024) {
            throw $refuse(__('The sheet is larger than 10 MB. Split it into smaller files.'));
        }

        $path = (string) $file->getRealPath();
        $head = (string) file_get_contents($path, false, null, 0, 4);

        if ($extension === 'xlsx') {
            $zip = new ZipArchive;
            $ok = $head === "PK\x03\x04" && $zip->open($path, ZipArchive::RDONLY) === true;
            $workbook = $ok && $zip->locateName('xl/workbook.xml') !== false && $zip->locateName('[Content_Types].xml') !== false;
            if ($ok) {
                $zip->close();
            }

            return $workbook ? 'xlsx' : throw $refuse(__('This is not an Excel (.xlsx) file. Download the template and fill it in.'));
        }

        if ($extension === 'csv') {
            $text = (string) file_get_contents($path);

            return mb_check_encoding($text, 'UTF-8') && ! str_contains($text, "\0") && trim($text) !== ''
                ? 'csv'
                : throw $refuse(__('The CSV must be plain text saved as UTF-8.'));
        }

        throw $refuse(__('Upload the sheet as .xlsx (or .csv).'));
    }

    /**
     * @throws ValidationException key `photos`
     */
    public function zip(UploadedFile $file): void
    {
        $refuse = fn (string $why): ValidationException => ValidationException::withMessages(['photos' => $why]);

        if (mb_strtolower($file->getClientOriginalExtension()) !== 'zip') {
            throw $refuse(__('Upload the photos as a .zip file.'));
        }

        if ($file->getSize() > self::ZIP_MAX_KB * 1024) {
            throw $refuse(__('The photo zip is larger than 200 MB.'));
        }

        $path = (string) $file->getRealPath();
        $zip = new ZipArchive;
        if ((string) file_get_contents($path, false, null, 0, 4) !== "PK\x03\x04" || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw $refuse(__('This is not a valid .zip file.'));
        }

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw $refuse(__('The zip holds more than :max files.', ['max' => self::MAX_ENTRIES]));
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += (int) ($stat['size'] ?? 0);
                if ($total > self::TOTAL_MAX_BYTES) {
                    throw $refuse(__('The photos in the zip add up to more than 1 GB once unpacked.'));   // zip-bomb guard
                }
                $name = (string) ($stat['name'] ?? '');
                if (str_ends_with($name, '/')) {
                    continue;   // a folder
                }

                if (self::unsafePath($name)) {
                    throw $refuse(__('The zip contains a file path that is not allowed (:name).', ['name' => basename($name)]));
                }

                if (! in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::PHOTO_EXTENSIONS, true)) {
                    throw $refuse(__('Only photos (JPG, PNG, WebP, HEIC) may be in the zip — :name is not one.', ['name' => basename($name)]));
                }

                if ((int) ($stat['size'] ?? 0) > self::ENTRY_MAX_BYTES) {
                    throw $refuse(__(':name is larger than 10 MB.', ['name' => basename($name)]));
                }
            }
        } finally {
            $zip->close();
        }
    }

    /** Zip-slip: an entry that would land outside the folder it is extracted into. */
    public static function unsafePath(string $name): bool
    {
        return $name === ''
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || preg_match('/^[A-Za-z]:/', $name) === 1
            || in_array('..', explode('/', $name), true)
            || str_contains($name, "\0");
    }
}
