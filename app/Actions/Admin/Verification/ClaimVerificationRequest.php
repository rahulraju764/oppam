<?php

declare(strict_types=1);

namespace App\Actions\Admin\Verification;

use App\Actions\Admin\Verification\Concerns\DecidesVerificationRequests;
use App\Domain\Verification\VerificationQueue;
use App\Exceptions\Verification\VerificationRequestUnavailable;
use App\Models\AdminUser;
use App\Models\VerificationRequest;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Open a request for review (A05 "claim lock", as A04): the officer holds it for
 * `oppam.verification.claim_minutes`; others see "being reviewed by …" and can neither decide it
 * nor open its documents. Release gives it back ("skip"). Both tell open queue pages live.
 */
final class ClaimVerificationRequest
{
    use DecidesVerificationRequests;

    public function __construct(private readonly VerificationQueue $queue) {}

    /**
     * @throws AuthorizationException
     * @throws VerificationRequestUnavailable
     */
    public function handle(AdminUser $admin, VerificationRequest $request): void
    {
        $this->authorizeDecision($admin, $request);
        $this->holdClaim($admin, $request);
        $this->queue->announceClaim();
    }

    public function release(AdminUser $admin, VerificationRequest $request): void
    {
        VerificationRequest::query()
            ->whereKey($request->id)
            ->where('claimed_by_admin_id', $admin->id)
            ->update(['claimed_by_admin_id' => null, 'claimed_until' => null, 'updated_at' => now()]);

        $this->queue->announceClaim();
    }
}
