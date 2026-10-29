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

    /** B.9 step 4: the attestation and the import belong to the person who uploaded the file. */
    public static function notUploader(): self
    {
        return new self(__('Only the person who uploaded this file can confirm consent and import it.'), 'BROKER_IMPORT_NOT_UPLOADER');
    }

    /** R-M13-27: no import without the uploader's attestation. */
    public static function notAttested(): self
    {
        return new self(__('Confirm that every client in this file has agreed before importing.'), 'BROKER_IMPORT_CONSENT');
    }

    /** R-M13-31: today's allowance is used up. */
    public static function noAllowance(): self
    {
        return new self(__('Your bureau has used today’s import allowance. You can import the remaining rows tomorrow.'), 'BROKER_IMPORT_DAILY_CAP');
    }

    public static function nothingToImport(): self
    {
        return new self(__('There are no rows ready to import. Fix the rows with errors first.'), 'BROKER_IMPORT_EMPTY');
    }
}
