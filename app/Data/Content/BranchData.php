<?php

declare(strict_types=1);

namespace App\Data\Content;

/** One office on /branches. */
final readonly class BranchData
{
    public function __construct(
        public string $city,
        public string $role,
        public string $address,
        public string $phone,
        public string $tel,
        public string $hours,
        public ?string $mapUrl = null,
    ) {}
}
