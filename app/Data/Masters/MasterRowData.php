<?php

declare(strict_types=1);

namespace App\Data\Masters;

/** What an admin types for a master row in A11: code (new rows only — immutable after), label, Malayalam label. */
final readonly class MasterRowData
{
    public function __construct(
        public string $code,
        public string $label,
        public ?string $labelMl = null,
    ) {}

    public static function from(string $code, string $label, ?string $labelMl = null): self
    {
        $labelMl = $labelMl !== null ? trim($labelMl) : null;

        return new self(strtoupper(trim($code)), trim($label), $labelMl === '' ? null : $labelMl);
    }
}
