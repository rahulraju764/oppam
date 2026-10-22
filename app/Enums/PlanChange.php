<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What buying a plan does to the member's current one (decisions 2026-10-21):
 *  - New:     no current paid plan — starts now;
 *  - Extend:  the same plan — the new months start when the current plan ends;
 *  - Upgrade: a higher plan — starts now, the current plan is cancelled (no proration);
 *  - Downgrade: a lower plan — refused at checkout while the current plan runs.
 */
enum PlanChange: string
{
    case New = 'NEW';
    case Extend = 'EXTEND';
    case Upgrade = 'UPGRADE';
    case Downgrade = 'DOWNGRADE';
}
