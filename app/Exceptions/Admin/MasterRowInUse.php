<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use RuntimeException;

/** A11: a master row that profiles or preferences still use can't be deleted — deactivate it instead. */
final class MasterRowInUse extends RuntimeException
{
    public static function make(string $label): self
    {
        return new self(__('“:label” is in use, so it can\'t be deleted. Deactivate it instead — existing profiles keep it, new ones can\'t pick it.', ['label' => $label]));
    }
}
