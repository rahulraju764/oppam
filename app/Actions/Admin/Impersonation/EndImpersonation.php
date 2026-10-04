<?php

declare(strict_types=1);

namespace App\Actions\Admin\Impersonation;

use App\Enums\ImpersonationEndReason;
use App\Events\Account\ForceLogout;
use App\Models\AdminUser;
use App\Models\ImpersonationSession;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use App\Support\Realtime\LiveSignal;
use Illuminate\Support\Facades\DB;

/**
 * Close an impersonation (P1.7b): ended by the admin, signed out, expired, replaced or never
 * used. Idempotent — an ended session is left as it is. Audited (the end row pairs with the
 * start row); the actor is the admin when they ended it, the system otherwise. Signing the
 * browser out of the member session is the caller's job (it owns the request).
 */
final class EndImpersonation
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(ImpersonationSession $session, ImpersonationEndReason $reason, ?AdminUser $actor = null): void
    {
        DB::transaction(function () use ($session, $reason, $actor): void {
            $locked = ImpersonationSession::query()->whereKey($session->id)->lockForUpdate()->first();

            if ($locked === null || $locked->ended_at !== null) {
                return;
            }

            $locked->forceFill(['ended_at' => now(), 'end_reason' => $reason, 'token_hash' => null])->save();
            $session->setRawAttributes($locked->getAttributes(), true);

            // The support tab (only that one — never the member's own) leaves at once (P3.1).
            LiveSignal::afterCommit(new ForceLogout((string) $locked->user_id, ForceLogout::IMPERSONATION));

            $this->audit->record('impersonation.ended', $locked->user, after: [
                'impersonation' => $locked->id,
                'admin' => $locked->admin_label,
                'end' => $reason->value,
                'minutes' => $locked->started_at !== null ? (int) $locked->started_at->diffInMinutes(now(), true) : 0,
            ], actor: $actor, subjectLabel: Profile::withTrashed()->where('user_id', $locked->user_id)->value('code'));
        });
    }
}
