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

    /** Plain copies live in `{temp}/oppam-import-{random}/` only while a job reads them. */
    public const TEMP_PREFIX = 'oppam-import-';

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
        if (preg_match('/^[a-z]{2,4}$/', $extension) !== 1) {
            throw new RuntimeException('Unexpected file type.');
        }

        $in = $this->disk()->readStream($path);
        // A private folder with a random name per copy: never shared with another job or worker
        // (a tempnam() name renamed to add the extension is free again for the next caller).
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.self::TEMP_PREFIX.bin2hex(random_bytes(16));
        if (! is_resource($in) || ! @mkdir($dir, 0700)) {
            if (is_resource($in)) {
                fclose($in);
            }

            throw new RuntimeException('The import file is missing.');
        }

        $plain = $dir.DIRECTORY_SEPARATOR.'file.'.$extension;
        $out = fopen($plain, 'xb');
        if ($out === false) {
            fclose($in);
            @rmdir($dir);

            throw new RuntimeException('The import file could not be prepared.');
        }
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

    /** Remove a temporary plain copy and its private folder. */
    public static function forget(?string $plain): void
    {
        if ($plain === null) {
            return;
        }

        if (is_file($plain)) {
            @unlink($plain);
        }

        $dir = dirname($plain);
        if (str_starts_with(basename($dir), self::TEMP_PREFIX) && is_dir($dir)) {
            @rmdir($dir);
        }
    }

    /** Delete every stored file of one batch (its `imports/{batch}/` folder) — cancel and the 30-day purge. */
    public function purge(string $batchId): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $batchId) !== 1) {
            throw new RuntimeException('Unexpected batch id.');   // never a path built from anything else
        }

        $this->disk()->deleteDirectory('imports/'.$batchId);
    }

    private function disk(): Filesystem
    {
        return Storage::disk((string) config('oppam.media.private_disk'));
    }
}
