<?php

declare(strict_types=1);

namespace App\Exceptions\Admin;

use LogicException;

/** member_notes is append-only (A03): a note can never be changed or removed. */
final class MemberNoteIsImmutable extends LogicException
{
    public static function update(): self
    {
        return new self('Member notes are append-only and cannot be updated.');
    }

    public static function delete(): self
    {
        return new self('Member notes are append-only and cannot be deleted.');
    }
}
