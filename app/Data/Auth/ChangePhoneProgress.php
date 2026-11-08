<?php

declare(strict_types=1);

namespace App\Data\Auth;

/** Where a member is in "change mobile number" (M14 Account tab): which code the screen asks for next. */
final readonly class ChangePhoneProgress
{
    public function __construct(
        public bool $oldConfirmed,
        public ?string $newNumberMasked,
    ) {}
}
