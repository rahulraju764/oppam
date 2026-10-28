<?php

declare(strict_types=1);

namespace App\Services\Broker;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Bulk-import files at rest (CLAUDE.md security rule 7, PRD §11A B.18 "import files on the private
 * disk, encrypted"): the sheet and the photo zip are encrypted with the application's encrypter
 * (AES-256-CBC + MAC, APP_KEY; APP_PREVIOUS_KEYS keeps old files readable after a rotation) in
 * CHUNK-sized pieces — one encrypted record per line — so a 200 MB zip is never held in memory.
 * A copied disk or bucket reveals nothing. unseal() writes a plain copy to a temporary file only
 * for the job that reads it; the caller deletes it straight after (forget()).
 */
final class ImportFileVault
{
    private const CHUNK = 1024 * 1024;

    public function __construct(private readonly Encrypter $encrypter) {}

    /** Encrypt the local file at $source into $path on the private disk. */
    public function seal(string $source, string $path): void
    {
        $in = fopen($source, 'rb');
        $out = fopen('php://temp', 'w+b');
        if ($in === false || $out === false) {
            throw new RuntimeException('The import file could not be read.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, self::CHUNK);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                fwrite($out, $this->encrypter->encrypt($chunk, false)."\n");
            }
            rewind($out);

            if (! $this->disk()->writeStream($path, $out)) {
                throw new RuntimeException('The import file could not be stored.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    /**
     * A decrypted copy of $path in a temporary file (keeps $extension so readers know the type).
     *
     * @throws RuntimeException missing or not decryptable
     */
    public function unseal(string $path, string $extension): string
    {
        $in = $this->disk()->readStream($path);
        $temp = tempnam(sys_get_temp_dir(), 'oppam-import-');
        if (! is_resource($in) || $temp === false) {
            throw new RuntimeException('The import file is missing.');
        }

        $plain = $temp.'.'.$extension;
        rename($temp, $plain);
        $out = fopen($plain, 'wb');
        $broken = false;

        try {
            while (($line = fgets($in)) !== false) {
                $line = trim($line);
                if ($line !== '') {
                    fwrite($out, (string) $this->encrypter->decrypt($line, false));
                }
            }
        } catch (DecryptException) {
            $broken = true;
        } finally {
            fclose($in);
            fclose($out);
        }

        if ($broken) {
            self::forget($plain);   // after closing: Windows can't delete an open file

            throw new RuntimeException('The import file could not be decrypted.');
        }

        return $plain;
    }

    /** Remove a temporary plain copy. */
    public static function forget(?string $plain): void
    {
        if ($plain !== null && is_file($plain)) {
            @unlink($plain);
        }
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('oppam.media.private_disk'));
    }
}
