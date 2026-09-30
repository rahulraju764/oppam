<?php

declare(strict_types=1);

namespace App\Actions\Admin\Auth;

use App\Exceptions\Admin\AdminAuthenticationFailed;
use App\Models\AdminUser;
use App\Services\Admin\TwoFactorAuthenticator;
use App\Services\Audit\AuditLogger;

/**
 * First-login 2FA enrolment (PRD A01: "enrol on first login"): begin() stores a fresh,
 * unconfirmed secret; confirm() checks a code from the authenticator app, marks 2FA confirmed and
 * returns the 10 recovery codes — the only time they are ever shown in plain text.
 */
final class EnrolAdminTwoFactor
{
    public function __construct(
        private readonly TwoFactorAuthenticator $twoFactor,
        private readonly AuditLogger $audit,
    ) {}

    /** @return string the secret, for the QR code and the manual-entry key */
    public function begin(AdminUser $admin): string
    {
        if ($admin->hasConfirmedTwoFactor()) {
            throw AdminAuthenticationFailed::loginExpired();
        }

        $secret = $this->twoFactor->newSecret();
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();

        return $secret;
    }

    /** @return list<string> the plain recovery codes, shown once */
    public function confirm(AdminUser $admin, string $code): array
    {
        if ($admin->two_factor_secret === null || $admin->hasConfirmedTwoFactor()) {
            throw AdminAuthenticationFailed::loginExpired();
        }

        if (! $this->twoFactor->verify($admin->two_factor_secret, $code)) {
            throw AdminAuthenticationFailed::invalidCode();
        }

        $codes = $this->twoFactor->newRecoveryCodes();
        $admin->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes['hashes'],
        ])->save();

        $this->audit->record('admin.two_factor_enabled', $admin, actor: $admin, subjectLabel: $admin->email);

        return $codes['plain'];
    }
}
