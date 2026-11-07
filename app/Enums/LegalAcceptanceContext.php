<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a member agreed to a legal version: sign-up, the blocking page, or the update banner. */
enum LegalAcceptanceContext: string
{
    case Registration = 'REGISTRATION';
    case Required = 'REQUIRED';
    case Notice = 'NOTICE';
}
