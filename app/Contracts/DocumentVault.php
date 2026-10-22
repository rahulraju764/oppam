<?php

declare(strict_types=1);

namespace App\Contracts;

use RuntimeException;

/**
 * Where ID documents live (PRD M09 / CLAUDE.md rule 7): private storage, encrypted at rest, under
 * names that say nothing. Nothing in it has a URL — the bytes are only read back by the audited
 * admin viewer and deleted by the purge.
 */
interface DocumentVault
{
    /** Encrypt and store the bytes; returns the stored path (random, no extension). */
    public function put(string $contents): string;

    /**
     * The decrypted bytes.
     *
     * @throws RuntimeException when the file is missing or can't be decrypted
     */
    public function get(string $path): string;

    public function delete(string $path): void;

    public function exists(string $path): bool;

    /** The disk name stored with each document row. */
    public function disk(): string;
}
