<?php

declare(strict_types=1);

namespace App\Actions\Admin\Impersonation;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ImpersonationEndReason;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Models\User;
use App\Notifications\Account\ImpersonationStarted;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * An admin starts a time-boxed impersonation of a member (PRD A01 / A03, P1.7b):
 * - needs `members.impersonate` and a typed reason; active, phone-verified members only;
 * - one live impersonation per member (another admin's live one → refused) and per admin (this
 *   admin's earlier one is ended as REPLACED);
 * - returns a single-use handoff token (only its SHA-256 is stored) valid for handoff_seconds,
 *   usable only from the admin's IP — EnterImpersonation swaps it for a member session;
 * - audited (start), and the member is emailed now (owner decision 2026-10-02: at the start).
 */
final class StartImpersonation
{
    use ChangesMemberState;

    /** Starts per admin and member in 10 minutes (each start emails the member). */
    public const MAX_STARTS = 3;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly EndImpersonation $end,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @return string the plain handoff token (never stored)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict
     */
    public function handle(AdminUser $admin, User $member, string $reason, ?string $ip): string
    {
        Gate::forUser($admin)->authorize('members.impersonate');
        $reason = $this->validatedReason($reason);

        $key = 'impersonate:'.$admin->id.':'.$member->id;
        if ($this->limiter->tooManyAttempts($key, self::MAX_STARTS)) {
            throw MemberStateConflict::tooManyStarts();
        }
        $this->limiter->hit($key, 600);

        $token = Str::random(64);

        [$session, $replaced, $member] = DB::transaction(function () use ($admin, $member, $reason, $ip, $token): array {
            $member = $this->lockMember($member);

            if ($member->trashed() || $member->status !== UserStatus::Active || ! $member->hasVerifiedPhone()) {
                throw MemberStateConflict::notActive();
            }

            if (ImpersonationSession::query()->live()->where('user_id', $member->id)->where('admin_user_id', '!=', $admin->id)->exists()) {
                throw MemberStateConflict::beingImpersonated();
            }

            $replaced = ImpersonationSession::query()->whereNull('ended_at')->where('admin_user_id', $admin->id)->lockForUpdate()->get();

            $session = new ImpersonationSession;
            $session->forceFill([
                'admin_user_id' => $admin->id,
                'admin_label' => $admin->email,
                'user_id' => $member->id,
                'reason' => $reason,
                'token_hash' => hash('sha256', $token),
                'token_expires_at' => now()->addSeconds((int) config('oppam.impersonation.handoff_seconds')),
                'ip_address' => $ip,
            ])->save();

            $this->audit->record('impersonation.started', $member, after: [
                'impersonation' => $session->id,
                'minutes' => (int) config('oppam.impersonation.minutes'),
            ], reason: $reason, actor: $admin, subjectLabel: $member->profile()->withTrashed()->value('code'));

            return [$session, $replaced, $member];
        });

        foreach ($replaced as $earlier) {
            $this->end->handle($earlier, $earlier->started_at === null ? ImpersonationEndReason::NotUsed : ImpersonationEndReason::Replaced, $admin);
        }

        if ($member->canReceiveAccountMail()) {
            $member->notify(new ImpersonationStarted($session->created_at));
        }

        return $token;
    }
}
