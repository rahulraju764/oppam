<?php

declare(strict_types=1);

namespace App\Actions\Admin\Brokers\Concerns;

use App\Enums\KycSubmissionStatus;
use App\Models\AdminUser;
use App\Models\Broker;
use App\Models\BrokerKyc;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules of the A07 KYC decisions: `brokers.kyc`, a typed reason where one is needed, and
 * the submission / bureau rows locked and re-checked inside the transaction, so two admins can't
 * decide the same submission (the second one is told it was already decided).
 */
trait DecidesBrokerKyc
{
    /** @throws AuthorizationException */
    private function authorizeKyc(AdminUser $admin): void
    {
        Gate::forUser($admin)->authorize('brokers.kyc');
    }

    /** @throws ValidationException */
    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 300) {
            throw ValidationException::withMessages(['reason' => __('Give a reason of 5 to 300 characters (the bureau owner reads it).')]);
        }

        return $reason;
    }

    /**
     * @return array{0: Broker, 1: BrokerKyc}
     *
     * @throws ValidationException
     */
    private function lockPendingKyc(BrokerKyc $kyc): array
    {
        $broker = Broker::query()->whereKey($kyc->broker_id)->lockForUpdate()->firstOrFail();
        $current = BrokerKyc::query()->whereKey($kyc->id)->lockForUpdate()->firstOrFail();

        if ($current->status !== KycSubmissionStatus::Pending) {
            throw ValidationException::withMessages(['reason' => __('This KYC submission has already been decided.')]);
        }

        return [$broker, $current];
    }
}
