<?php

declare(strict_types=1);

namespace App\Data\Admin;

use Carbon\CarbonImmutable;

/**
 * The A13 conversation overview (PRD A13 "metadata only"): counts and member codes — never message
 * text. Rows are plain arrays of scalars so the whole object can be cached.
 */
final readonly class ChatOverviewData
{
    /**
     * @param  array{labels: list<string>, values: list<int>}  $perDay  messages per IST day, last 30 days (snapshots)
     * @param  list<array{code: string, messages: int, threads: int}>  $topSenders  last 7 days
     * @param  list<array{sender: string, recipient: string, unanswered: int, last_at: string|null}>  $oneSided
     * @param  list<array{code: string, joined: string, threads: int}>  $newAccounts  last 24 hours
     */
    public function __construct(
        public array $perDay,
        public array $topSenders,
        public array $oneSided,
        public array $newAccounts,
        public CarbonImmutable $asOf,
        public int $oneSidedThreshold,
        public int $newAccountThreads,
    ) {}
}
