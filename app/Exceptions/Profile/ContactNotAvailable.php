<?php

declare(strict_types=1);

namespace App\Exceptions\Profile;

use App\Enums\ContactAccess;
use RuntimeException;

/** A contact reveal was refused (R-M03-2); the message is safe to show the member. */
final class ContactNotAvailable extends RuntimeException
{
    public function __construct(public readonly ContactAccess $access)
    {
        parent::__construct($access->message());
    }
}
