<?php

declare(strict_types=1);

namespace App\Exceptions\Broker;

use RuntimeException;

/**
 * "Is this you?" was confirmed, but there is (no longer) a profile this member may claim — already
 * claimed from another tab, deleted by the bureau meanwhile, or the wizard was started (§11A B.6).
 */
final class ClaimUnavailable extends RuntimeException
{
    public static function nothingToClaim(): self
    {
        return new self(__('This profile can no longer be claimed. Please continue with your own profile.'));
    }
}
