<?php

declare(strict_types=1);

namespace App\Events\Admin;

use App\Enums\ModerationItemType;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** An A04 queue gained or lost an item (photo uploaded / deleted, …). Fired after commit. */
final class ModerationQueueChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly ModerationItemType $type) {}
}
