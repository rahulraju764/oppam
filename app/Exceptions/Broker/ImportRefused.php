<?php

declare(strict_types=1);

namespace App\Exceptions\Broker;

use RuntimeException;

/** A bulk-import step the bureau's state doesn't allow (§11A B.9). The message is safe to show. */
final class ImportRefused extends RuntimeException
{
    private function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEnabled(): self
    {
        return new self(__('Bulk import is coming soon. You can add profiles one at a time meanwhile.'), 'BROKER_IMPORT_DISABLED');
    }

    /** R-A07-9: A07 paused this bureau's imports; single-profile creation still works. */
    public static function paused(): self
    {
        return new self(__('Imports are paused for your bureau. Please contact Oppam support. You can still add profiles one at a time.'), 'BROKER_IMPORTS_PAUSED');
    }

    public static function wrongStatus(): self
    {
        return new self(__('This batch can’t be changed at its current stage.'), 'BROKER_IMPORT_STATUS');
    }
}
