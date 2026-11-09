<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Comms\BounceReport;
use Illuminate\Http\Request;

/**
 * Checks and reads a mail provider's bounce webhook (A14 delivery log, P8.3a). Returns the reports
 * it found, or null when the request isn't provably from the provider (bad / missing signature,
 * too old, replayed) — the caller then answers 403 and changes nothing. Fails closed.
 */
interface MailBounceVerifier
{
    /** @return list<BounceReport>|null */
    public function verify(Request $request): ?array;
}
