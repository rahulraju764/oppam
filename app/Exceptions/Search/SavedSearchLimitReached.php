<?php

declare(strict_types=1);

namespace App\Exceptions\Search;

use RuntimeException;

final class SavedSearchLimitReached extends RuntimeException
{
    public static function make(int $limit = 10): self
    {
        return new self(__('You can save up to :limit searches. Delete an existing search to save a new one.', ['limit' => $limit]));
    }
}
