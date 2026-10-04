<?php

declare(strict_types=1);

namespace App\Data\Masters;

/**
 * One CSV line in an A11 import preview: what it would do (new / update / same) or why it can't
 * (error), and which fields change.
 */
final readonly class MasterImportRow
{
    public const NEW = 'new';

    public const UPDATE = 'update';

    public const SAME = 'same';

    public const ERROR = 'error';

    /**
     * @param  list<string>  $changes  field names that change
     * @param  list<string>  $errors
     */
    public function __construct(
        public int $line,
        public string $code,
        public string $label,
        public ?string $labelMl,
        public ?int $sortOrder,
        public ?bool $isActive,
        public string $status,
        public array $changes = [],
        public array $errors = [],
    ) {}

    /** @return array<string, mixed> for the Livewire preview (plain values only) */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
