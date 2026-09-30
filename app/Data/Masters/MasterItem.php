<?php

declare(strict_types=1);

namespace App\Data\Masters;

use App\Models\Masters\MasterRecord;

/** One master-data option as the UI needs it (id for the form value, code, label). */
final readonly class MasterItem
{
    public function __construct(
        public int $id,
        public string $code,
        public string $label,
    ) {}

    public static function fromModel(MasterRecord $record): self
    {
        return new self($record->id, $record->code, $record->label);
    }
}
