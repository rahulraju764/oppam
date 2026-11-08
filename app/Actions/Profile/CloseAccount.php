<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Enums\ClosureReason;
use App\Enums\ModerationHold;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Profile\SettingsRefused;
use App\Models\ProfileClosure;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /settings → Hide / delete: close my account (PRD M14). Stage one of the same two-stage deletion
 * A03 uses: the account and profile are soft-deleted (status DELETED), every session ends (live
 * ForceLogout), paid plans pause and waiting moderation items are held. Inside
 * `members.purge_after_days` (30) the member restores it by signing in (RestoreClosedAccount);
 * afterwards PurgeDeletedMembers anonymises it (DPDP right to erasure).
 *  - the current password confirms it (5 wrong tries → 15 min wait, like ChangePassword);
 *  - "Found my match on Oppam" never closes: it starts the M16 story (hide, owner 2026-11-07 (3));
 *  - the free-text feedback is stored on the closure row only, never in the audit log;
 *  - refused in an admin support session.
 */
final class CloseAccount
{
    use ChangesMemberState;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ImpersonationRestricted
     * @throws SettingsRefused
     * @throws ValidationException
     */
    public function handle(User $user, ClosureReason $reason, ?string $feedback, #[SensitiveParameter] string $password): void
    {
        $this->impersonation->assertAllowed();

        if (! $user->isActive()) {
            throw new NotFoundHttpException;
        }

        if (! $reason->closesAccount()) {
            throw SettingsRefused::closeReasonStartsStory();
        }

        $feedback = $feedback !== null && trim($feedback) !== '' ? mb_substr(trim($feedback), 0, 1000) : null;
        if ($reason === ClosureReason::Other && $feedback === null) {
            throw ValidationException::withMessages(['feedback' => __('Please tell us a little about why you are leaving.')]);
        }

        $key = 'close-account:'.$user->id;
        $max = (int) config('oppam.auth.password_attempts');
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['password' => __('Too many attempts. Please try again in :minutes minutes.', ['minutes' => max(1, (int) ceil(RateLimiter::availableIn($key) / 60))])]);
        }

        if (! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, (int) config('oppam.auth.password_lockout_minutes') * 60);

            throw ValidationException::withMessages(['password' => __('That password isn’t right.')]);
        }

        RateLimiter::clear($key);

        DB::transaction(function () use ($user, $reason, $feedback): void {
            $member = $this->lockMember($user);
            if ($member->trashed() || $member->status !== UserStatus::Active) {
                throw new NotFoundHttpException;
            }

            $profile = $this->lockProfile($member);

            $closure = new ProfileClosure;
            $closure->forceFill([
                'user_id' => $member->id,
                'profile_id' => $profile?->id,
                'reason' => $reason,
                'feedback' => $feedback,
                'created_at' => now(),
            ])->save();

            $paused = 0;
            $held = 0;

            if ($profile !== null && ! $profile->trashed()) {
                $this->rememberStatus($profile);
                $profile->status = ProfileStatus::Deleted;
                $profile->save();
                $profile->delete();

                $paused = $this->pauseSubscriptions($profile);
                $held = $this->holdModerationItems($profile, ModerationHold::MemberDeleted);
            }

            $this->revokeSessions($member);
            $member->forceFill(['status' => UserStatus::Deleted])->save();
            $member->delete();

            $this->audit->record(
                'account.closed_by_member',
                $member,
                ['status' => UserStatus::Active->value],
                ['status' => UserStatus::Deleted->value, 'reason' => $reason->value, 'plans_paused' => $paused, 'moderation_held' => $held],
                actor: $member,
                subjectLabel: $profile?->code,
            );
        });
    }
}
