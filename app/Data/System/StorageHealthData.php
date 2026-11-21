<?php

declare(strict_types=1);

namespace App\Data\System;

/**
 * The Storage card of the A12 health panel: free / total space where the app writes local files
 * (null = the server wouldn't say) and each configured disk by name and driver — never a bucket,
 * key or path.
 */
final readonly class StorageHealthData
{
    /** @param  array<string, string>  $disks  disk name => driver */
    public function __construct(
        public ?int $freeBytes,
        public ?int $totalBytes,
        public array $disks,
    ) {}

    public function usedPct(): ?float
    {
        if ($this->freeBytes === null || $this->totalBytes === null || $this->totalBytes === 0) {
            return null;
        }

        return round(($this->totalBytes - $this->freeBytes) * 100 / $this->totalBytes, 1);
    }
}
