<?php

declare(strict_types=1);

namespace App\Data\System;

use App\Enums\SystemAlertKind;

/**
 * One run of the A12 alert rules: the thresholds crossed (kind => [value, threshold]) and the kinds
 * whose source couldn't be read (left as they are — neither opened nor closed).
 */
final readonly class AlertEvaluationData
{
    /**
     * @param  array<string, array{value: int, threshold: int}>  $breaches  keyed by SystemAlertKind value
     * @param  list<SystemAlertKind>  $unknown
     */
    public function __construct(
        public array $breaches,
        public array $unknown,
    ) {}

    public function breached(SystemAlertKind $kind): bool
    {
        return isset($this->breaches[$kind->value]);
    }

    public function isUnknown(SystemAlertKind $kind): bool
    {
        return in_array($kind, $this->unknown, true);
    }
}
