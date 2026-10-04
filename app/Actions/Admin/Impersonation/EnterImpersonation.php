<?php

declare(strict_types=1);

namespace App\Actions\Admin\Impersonation;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\ImpersonationSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The member-site half of the handoff (P1.7b): swap a single-use token for a running
 * impersonation. The token must be unused, within handoff_seconds and presented from the IP the
 * admin started it from; the member must still be an active, phone-verified MEMBER. On success
 * the 30 minutes start now and the token is gone (only its hash ever existed). Anything else is
 * null — the caller answers 404 without saying why.
 */
final class EnterImpersonation
{
    public function handle(string $token, ?string $ip): ?ImpersonationSession
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) !== 1) {
            return null;
        }

        return DB::transaction(function () use ($token, $ip): ?ImpersonationSession {
            $session = ImpersonationSession::query()->where('token_hash', hash('sha256', $token))->lockForUpdate()->first();

            if ($session === null || $session->ended_at !== null || $session->started_at !== null
                || $session->token_expires_at?->isFuture() !== true
                || $session->ip_address === null || $ip === null || ! hash_equals($session->ip_address, $ip)) {
                return null;
            }

            $member = User::query()->whereKey($session->user_id)->where('role', UserRole::Member->value)->first();
            if ($member === null || $member->status !== UserStatus::Active || ! $member->hasVerifiedPhone()) {
                return null;
            }

            $session->forceFill([
                'token_hash' => null,
                'started_at' => now(),
                'expires_at' => now()->addMinutes((int) config('oppam.impersonation.minutes')),
            ])->save();

            return $session;
        });
    }
}
