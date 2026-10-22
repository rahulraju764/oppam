<?php

declare(strict_types=1);

namespace App\Services\Verification;

use App\Contracts\DocumentVault;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * DocumentVault on a private Laravel disk (`oppam.verification.disk`: local `storage/app/private`
 * — `serve => false`, so the framework never serves it — or s3-private in production). The bytes
 * are encrypted with the application's encrypter (AES-256-CBC + MAC, APP_KEY; APP_PREVIOUS_KEYS
 * keeps old files readable after a key rotation), so a copied disk or bucket reveals nothing.
 */
final class EncryptedDocumentVault implements DocumentVault
{
    private const DIRECTORY = 'verification';

    public function __construct(
        private readonly Filesystems $filesystems,
        private readonly Encrypter $encrypter,
    ) {}

    public function put(string $contents): string
    {
        $path = self::DIRECTORY.'/'.Str::lower((string) Str::ulid());

        if (! $this->storage()->put($path, $this->encrypter->encrypt($contents, false))) {
            throw new RuntimeException('The document could not be stored.');
        }

        return $path;
    }

    public function get(string $path): string
    {
        $payload = $this->storage()->get($path);

        if (! is_string($payload) || $payload === '') {
            throw new RuntimeException('The document is missing.');
        }

        try {
            return (string) $this->encrypter->decrypt($payload, false);
        } catch (DecryptException) {
            throw new RuntimeException('The document could not be decrypted.');
        }
    }

    public function delete(string $path): void
    {
        $this->storage()->delete($path);
    }

    public function exists(string $path): bool
    {
        return $this->storage()->exists($path);
    }

    public function disk(): string
    {
        return (string) config('oppam.verification.disk');
    }

    private function storage(): Filesystem
    {
        return $this->filesystems->disk($this->disk());
    }
}
