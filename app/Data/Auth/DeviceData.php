<?php

declare(strict_types=1);

namespace App\Data\Auth;

use Carbon\CarbonImmutable;

/** One recent sign-in device on the Account tab (M14): display data only — no device id or full IP. */
final readonly class DeviceData
{
    public function __construct(
        public string $label,
        public string $ipMasked,
        public CarbonImmutable $lastSignedInAt,
        public bool $isThisDevice,
    ) {}
}
