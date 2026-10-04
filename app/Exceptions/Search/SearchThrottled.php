<?php

declare(strict_types=1);

namespace App\Exceptions\Search;

use RuntimeException;

/** Too many searches in a minute (M04 / CLAUDE.md rule 10). */
final class SearchThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(__('You are searching very quickly. Please wait :seconds seconds and try again.', ['seconds' => max(1, $retryAfterSeconds)]));
    }
}
