<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an entitlement's usage counter resets (PRD §7.3): daily at 00:00 IST, monthly on the
 * subscription anniversary (calendar month IST for Free — docs/decisions.md), lifetime never
 * (a cap on a total, e.g. favourites). None = an on/off flag with no counter.
 */
enum EntitlementPeriod: string
{
    case Daily = 'DAILY';
    case Monthly = 'MONTHLY';
    case Lifetime = 'LIFETIME';
    case None = 'NONE';
}
