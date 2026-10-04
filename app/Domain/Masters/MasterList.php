<?php

declare(strict_types=1);

namespace App\Domain\Masters;

use App\Models\Masters\MasterOption;
use App\Models\Masters\MasterRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * One editable master list in A11 (PRD §11 A11): which model holds it, how it is scoped (a parent
 * row for castes / states / districts, a group for the generic option lists) and every column
 * that references its rows — the FK columns (counted for "used by") and the JSON id lists in
 * partner preferences (checked before a delete). Rows are never deleted once used.
 */
final readonly class MasterList
{
    /**
     * @param  class-string<MasterRecord>  $model
     * @param  list<array{0: string, 1: string}>  $references  [table, column] holding a row id
     * @param  list<array{0: string, 1: string}>  $jsonReferences  [table, column] holding a JSON list of row ids
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $section,
        public string $model,
        public ?string $parentKey = null,
        public ?string $parentColumn = null,
        public ?string $group = null,
        public array $references = [],
        public array $jsonReferences = [],
        public bool $isWordList = false,
        public bool $allowsNewRows = true,
    ) {}

    public function hasParent(): bool
    {
        return $this->parentKey !== null && $this->parentColumn !== null;
    }

    /**
     * Every row of this list whatever its own parent (still within its option group) — for using
     * the list AS a parent (states as the parents of districts) and for parent dropdowns.
     *
     * @return Builder<MasterRecord>
     */
    public function anyParentQuery(): Builder
    {
        /** @var Builder<MasterRecord> $query */
        $query = $this->model::query();

        if ($this->group !== null) {
            $query->where((new MasterOption)->qualifyColumn('group'), $this->group);
        }

        return $query;
    }

    /**
     * Every row of this list in one scope (the chosen parent / the option group), any status.
     *
     * @return Builder<MasterRecord>
     */
    public function query(?int $parentId = null): Builder
    {
        /** @var Builder<MasterRecord> $query */
        $query = $this->model::query();

        if ($this->hasParent()) {
            $query->where((string) $this->parentColumn, $parentId ?? 0);
        }

        if ($this->group !== null) {
            $query->where((new MasterOption)->qualifyColumn('group'), $this->group);
        }

        return $query;
    }

    /**
     * The attributes a new row in this scope gets besides code / label.
     *
     * @return array<string, int|string>
     */
    public function scopeAttributes(?int $parentId): array
    {
        $attributes = [];

        if ($this->hasParent()) {
            $attributes[(string) $this->parentColumn] = (int) $parentId;
        }

        if ($this->group !== null) {
            $attributes['group'] = $this->group;
        }

        return $attributes;
    }
}
