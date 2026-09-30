<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Completed years between a date of birth and "today". Pure: the caller decides what today
 * is (members live in IST — an age changes at midnight IST, not UTC). A 29 February birthday
 * counts as reached on 1 March in non-leap years.
 */
final class AgeCalculator
{
    public static function ageOn(CarbonInterface $dob, CarbonInterface $today): int
    {
        $birth = [(int) $dob->format('Y'), (int) $dob->format('n'), (int) $dob->format('j')];
        $now = [(int) $today->format('Y'), (int) $today->format('n'), (int) $today->format('j')];

        if ($now < $birth) {
            throw new InvalidArgumentException('Date of birth is in the future.');
        }

        $age = $now[0] - $birth[0];
        $birthdayReached = [$now[1], $now[2]] >= [$birth[1], $birth[2]];

        return $birthdayReached ? $age : $age - 1;
    }
}
