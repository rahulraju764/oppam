<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\AccountSecurityChange;
use App\Enums\UserRole;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\EmailChangeRequest;
use App\Models\User;
use App\Notifications\Account\AccountSecurityChanged;
use App\Services\Admin\Impersonation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Remove the account email (M14, P8.2b): no more email of any kind — the member keeps signing in by
 * mobile. Open verification links stop working. In-app security notice; audited (never the address).
 */
final class RemoveEmail
{
    public function __construct(
        private readonly Impersonation $impersonation,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws ImpersonationRestricted */
    public function handle(User $member): void
    {
        $this->impersonation->assertAllowed();
        if (! $member->isActive() || $member->role !== UserRole::Member) {
            throw new NotFoundHttpException;
        }
        if ($member->email === null) {
            return;
        }

        DB::transaction(function () use ($member): void {
            DB::table('password_reset_tokens')->where('email', (string) $member->email)->delete();
            $member->forceFill(['email' => null, 'email_verified_at' => null])->save();
            EmailChangeRequest::query()->where('user_id', $member->id)->whereNull('consumed_at')->update(['expires_at' => now()]);
        });

        $this->audit->record('account.email_removed', $member, [], ['email_removed' => true], actor: $member, subjectLabel: $member->profile?->code);
        $member->notify(new AccountSecurityChanged(AccountSecurityChange::EmailRemoved));
    }
}
