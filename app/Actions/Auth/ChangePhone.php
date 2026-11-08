<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Data\Auth\ChangePhoneProgress;
use App\Domain\Safety\BanList;
use App\Enums\AccountSecurityChange;
use App\Enums\BannedIdentifierType;
use App\Enums\OtpPurpose;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use App\Support\Auth\SessionRotation;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Change the mobile number — the member's login id (PRD M14 Account; owner decisions 2026-11-08).
 * Two codes, both proven on this device, in order; the progress lives in the server session:
 *  1. start → a CHANGE_PHONE code to the CURRENT number; confirmOld proves the member still has it
 *     (good for `oppam.auth.change_phone_window_minutes`);
 *  2. sendNew → a code to the NEW number; confirmNew proves it and swaps the number.
 * A new number that is taken (another account, a bureau-managed profile's client number, the ban
 * list) gets exactly the same screen as a free one but no code — nothing reveals that a number is
 * registered (R-M01-1, R-M01-4); the member is told to contact support if no code arrives.
 * Swapping: re-checked under the user row lock, phone_verified_at = now, every other device signed
 * out (SessionRotation: epoch, remember token, live ForceLogout); this device stays in. Then a
 * security notice (in-app + verified email, no SMS — owner) and an audit row (phone masked).
 * Members only; never in an admin support session.
 */
final class ChangePhone
{
    public const SESSION_KEY = 'account.change_phone';

    public function __construct(
        private readonly SendOtp $sendOtp,
        private readonly VerifyOtp $verifyOtp,
        private readonly BanList $bans,
        private readonly Impersonation $impersonation,
        private readonly SessionRotation $rotation,
        private readonly AuditLogger $audit,
        private readonly Session $session,
    ) {}

    /**
     * Step 1a: a code to the current number.
     *
     * @throws OtpThrottled
     * @throws ImpersonationRestricted
     */
    public function start(User $member, string $ip): void
    {
        $this->guard($member);
        $this->session->forget(self::SESSION_KEY);

        $this->sendOtp->handle(PhoneNumber::fromE164($member->phone), OtpPurpose::ChangePhone, $ip, $member);
    }

    /**
     * Step 1b: the current number's code.
     *
     * @throws OtpInvalid
     * @throws ImpersonationRestricted
     */
    public function confirmOld(User $member, string $code): void
    {
        $this->guard($member);

        $challenge = $this->verifyOtp->handle(PhoneNumber::fromE164($member->phone), OtpPurpose::ChangePhone, $code);
        if ($challenge->user_id !== $member->id) {
            throw OtpInvalid::wrongOrExpired();
        }

        $this->session->put(self::SESSION_KEY, ['user' => (string) $member->id, 'old_ok_at' => now()->getTimestamp(), 'new' => null]);
    }

    /**
     * Step 2a: a code to the new number (none when the number can't be used — same screen).
     *
     * @throws AccountChangeRefused
     * @throws OtpThrottled
     * @throws ImpersonationRestricted
     */
    public function sendNew(User $member, PhoneNumber $new, string $ip): void
    {
        $this->guard($member);
        $state = $this->step1Done($member);

        if ($new->e164() === $member->phone) {
            throw AccountChangeRefused::sameNumber();
        }

        $this->sendOtp->handle($new, OtpPurpose::ChangePhone, $ip, $this->isFree($new) ? $member : null);

        $this->session->put(self::SESSION_KEY, [...$state, 'new' => $new->e164()]);
    }

    /**
     * Step 2b: the new number's code — the number is swapped.
     *
     * @throws AccountChangeRefused
     * @throws OtpInvalid
     * @throws ImpersonationRestricted
     */
    public function confirmNew(User $member, string $code, Request $request): void
    {
        $this->guard($member);
        $state = $this->step1Done($member);
        if ($state['new'] === null) {
            throw AccountChangeRefused::confirmCurrentNumberFirst();
        }
        $new = PhoneNumber::fromE164($state['new']);

        $challenge = $this->verifyOtp->handle($new, OtpPurpose::ChangePhone, $code);
        if ($challenge->user_id !== $member->id) {
            throw OtpInvalid::wrongOrExpired();
        }

        $old = $member->phone;
        $this->rotation->rotateKeepingThisDevice($member, $request, function (User $locked) use ($new): void {
            if (! $this->isFree($new)) {   // taken between the two steps
                throw AccountChangeRefused::numberUnavailable();
            }
            $locked->forceFill(['phone' => $new->e164(), 'phone_verified_at' => now()]);
        });

        $this->session->forget(self::SESSION_KEY);
        $this->audit->record('account.phone_changed', $member, ['phone' => $old], ['phone' => $new->e164()], actor: $member, subjectLabel: $member->profile?->code);
        $member->notify(new AccountSecurityChanged(AccountSecurityChange::PhoneChanged));
    }

    /** Which step the member is on: 'old' (nothing proven yet), 'new' (current number proven), with the masked new number. */
    public function progress(User $member): ChangePhoneProgress
    {
        $state = $this->state($member);

        return new ChangePhoneProgress(
            oldConfirmed: $state !== null,
            newNumberMasked: $state !== null && is_string($state['new']) ? PhoneNumber::fromE164($state['new'])->masked() : null,
        );
    }

    public function cancel(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /** Free = no account (even a deleted one) holds it, no managed profile's client holds it, not banned. */
    private function isFree(PhoneNumber $phone): bool
    {
        return ! User::withTrashed()->where('phone', $phone->e164())->exists()
            && ! Profile::withTrashed()->where('client_phone', $phone->e164())->exists()
            && ! $this->bans->isBanned(BannedIdentifierType::Phone, $phone->e164());
    }

    private function guard(User $member): void
    {
        $this->impersonation->assertAllowed();

        if (! $member->isActive() || $member->role !== UserRole::Member) {
            throw new NotFoundHttpException;
        }
    }

    /**
     * @return array{user: string, old_ok_at: int, new: string|null}
     *
     * @throws AccountChangeRefused
     */
    private function step1Done(User $member): array
    {
        return $this->state($member) ?? throw AccountChangeRefused::confirmCurrentNumberFirst();
    }

    /** @return array{user: string, old_ok_at: int, new: string|null}|null the live step-1 proof for this member */
    private function state(User $member): ?array
    {
        $state = $this->session->get(self::SESSION_KEY);
        if (! is_array($state) || ($state['user'] ?? null) !== (string) $member->id || ! is_int($state['old_ok_at'] ?? null)) {
            return null;
        }

        $window = (int) config('oppam.auth.change_phone_window_minutes') * 60;
        if (now()->getTimestamp() - $state['old_ok_at'] > $window) {
            $this->session->forget(self::SESSION_KEY);

            return null;
        }

        $new = $state['new'] ?? null;

        return ['user' => (string) $state['user'], 'old_ok_at' => $state['old_ok_at'], 'new' => is_string($new) ? $new : null];
    }
}
