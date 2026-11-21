<?php

declare(strict_types=1);

namespace App\Data\System;

/** One table's size on the A12 health panel (information_schema: data + index bytes, estimated rows). */
final readonly class TableSizeData
{
    public function __construct(
        public string $name,
        public int $bytes,
        public int $rows,
    ) {}
}
