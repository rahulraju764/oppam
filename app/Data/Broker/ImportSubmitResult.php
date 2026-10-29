<?php

declare(strict_types=1);

namespace App\Data\Broker;

/** What "Submit all complete profiles" did (§11A B.9 step 6). */
final readonly class ImportSubmitResult
{
    public function __construct(
        public int $submitted,
        public int $incomplete,
    ) {}
}
