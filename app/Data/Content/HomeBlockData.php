<?php

declare(strict_types=1);

namespace App\Data\Content;

/** The published text of one page section. */
final readonly class HomeBlockData
{
    /** @param array<string, string> $fields */
    public function __construct(private array $fields) {}

    public function text(string $field): string
    {
        return $this->fields[$field] ?? '';
    }
}
