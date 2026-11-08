<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Safety\BanList;
use App\Enums\AccountSecurityChange;
use App\Enums\BannedIdentifierType;
use App\Exceptions\Auth\AccountChangeRefused;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Opening an email verification link (M14, P8.2b). The token is matched by its hash; the link
 * must be unused and unexpired, the account still active, and the address still free. Then, in one
 * transaction: the account's email becomes this address, verified now; the link and every other
 * open link of the account are used up. Opening it signed in as a different account is refused
 * (the member would think their own account changed). Audited without the address itself.
 */
final class ConfirmEmailChange
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly BanList $bans,
    ) {}

    /** @throws AccountChangeRefused */
    public function handle(string $token, ?User $signedIn): User
    {
        $hash = hash('sha256', $token);

        try {
            $member = $this->confirm($hash, $signedIn);
        } catch (UniqueConstraintViolationException) {   // claimed by someone else at the same moment
            throw AccountChangeRefused::emailUnavailable();
        }

        $member->notify(new AccountSecurityChanged(AccountSecurityChange::EmailVerified));

        return $member;
    }

    /**
     * @throws AccountChangeRefused
     * @throws UniqueConstraintViolationException another account took the address at this moment
     */
    private function confirm(string $hash, ?User $signedIn): User
    {
        return DB::transaction(function () use ($hash, $signedIn): User {
            $row = EmailChangeRequest::query()->where('token_hash', $hash)->lockForUpdate()->first();
            if ($row === null || $row->consumed_at !== null || $row->expires_at->isPast()) {
                throw AccountChangeRefused::linkInvalid();
            }
            if ($signedIn !== null && $signedIn->id !== $row->user_id) {
                throw AccountChangeRefused::linkForAnotherAccount();
            }

            $member = User::query()->lockForUpdate()->find($row->user_id);
            if ($member === null || ! $member->isActive()) {
                throw AccountChangeRefused::linkInvalid();
            }
            if (User::withTrashed()->where('email', $row->email)->whereKeyNot($member->id)->exists()
                || $this->bans->isBanned(BannedIdentifierType::Email, $row->email)) {
                throw AccountChangeRefused::emailUnavailable();
            }

            if ($member->email !== null && $member->email !== $row->email) {
                DB::table('password_reset_tokens')->where('email', $member->email)->delete();   // the old address's reset link dies
            }
            $member->forceFill(['email' => $row->email, 'email_verified_at' => now()])->save();
            EmailChangeRequest::query()->where('user_id', $member->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);
            $this->audit->record('account.email_verified', $member, [], ['email_verified' => true], actor: $member, subjectLabel: $member->profile?->code);

            return $member;
        });
    }
}
