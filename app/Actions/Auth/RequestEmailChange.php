<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\Auth\EmailChangeRequested;
use App\Notifications\Auth\VerifyEmailAddress;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Add, change or verify the account email (PRD M14 Account; owner decisions 2026-11-08). A
 * single-use link goes to the address asked for; the account's email changes only when it is
 * opened (ConfirmEmailChange), so the current address stays in use until then — and, when it is
 * verified, is told a change was asked for. An address held by another account or on the ban
 * list is refused with ONE generic message — never which reason (R-M01-1, R-M01-4); the daily
 * limit is spent first either way. Earlier open links stop working. At most A15
 * `members.email_changes_per_day` requests a day.
 * Members only; never in an admin support session.
 */
final class RequestEmailChange
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly BanList $bans,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * @throws AccountChangeRefused
     * @throws ImpersonationRestricted
     */
    public function handle(User $member, string $email): void
    {
        $this->impersonation->assertAllowed();
        if (! $member->isActive() || $member->role !== UserRole::Member) {
            throw new NotFoundHttpException;
        }

        $email = Str::lower(trim($email));
        if ($email === $member->email && $member->email_verified_at !== null) {
            throw AccountChangeRefused::sameEmail();
        }

        $key = 'email-change:'.$member->id;
        if ($this->limiter->hit($key, 86_400) > Settings::int(SettingKey::MembersEmailChangesPerDay)) {   // counted first
            throw AccountChangeRefused::tooManyEmails();
        }

        if (! $this->isFree($member, $email)) {
            throw AccountChangeRefused::emailUnavailable();
        }

        $token = Str::random(64);
        $hours = (int) config('oppam.auth.email_verify_hours');

        DB::transaction(function () use ($member, $email, $token, $hours): void {
            EmailChangeRequest::query()->where('user_id', $member->id)->whereNull('consumed_at')
                ->where('expires_at', '>', now())->update(['expires_at' => now()]);

            $row = new EmailChangeRequest;
            $row->forceFill([
                'user_id' => $member->id,
                'email' => $email,
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours($hours),
            ])->save();
        });

        // After the response, not queued: the token never reaches the queue store; failures are
        // logged without the address or the link.
        $verify = new VerifyEmailAddress(route('member.email.verify', ['token' => $token]), $hours);
        defer(static function () use ($email, $verify): void {
            try {
                Notification::route('mail', $email)->notifyNow($verify);
            } catch (Throwable) {
                Log::warning('Email verification mail failed');
            }
        });

        if ($member->canReceiveAccountMail() && $member->email !== $email) {
            Notification::route('mail', (string) $member->email)->notify(new EmailChangeRequested);
        }
    }

    /** Free = no other account (even a deleted one) holds it, and it isn't banned. */
    private function isFree(User $member, string $email): bool
    {
        return ! User::withTrashed()->where('email', $email)->whereKeyNot($member->id)->exists()
            && ! $this->bans->isBanned(BannedIdentifierType::Email, $email);
    }
}
