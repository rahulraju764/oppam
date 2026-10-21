<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Exceptions\Engagement\SignalUnavailable;
use App\Models\AdminUser;
use App\Models\MemberRestriction;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * End a member's "pause sending" early (PRD A13). Needs `chat.moderate` and a typed reason (5 – 500);
 * audited ("chat.restriction.lifted"). A pause that already ended or was lifted: 409.
 */
final class LiftRestriction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws SignalUnavailable
     */
    public function handle(AdminUser $admin, MemberRestriction $restriction, string $reason): void
    {
        Gate::forUser($admin)->authorize('chat.moderate');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']], [], ['reason' => __('reason')])->validate();

        DB::transaction(function () use ($admin, $restriction, $reason): void {
            $locked = MemberRestriction::query()->whereKey($restriction->id)->lockForUpdate()->firstOrFail();
            if ($locked->lifted_at !== null || $locked->until->isPast()) {
                throw SignalUnavailable::lifted();
            }

            $locked->lifted_at = now();
            $locked->lifted_by_admin_id = $admin->id;
            $locked->save();

            $this->audit->record('chat.restriction.lifted', $locked, ['until' => $locked->until->toIso8601String()], ['lifted' => true],
                reason: $reason, actor: $admin, subjectLabel: Profile::withTrashed()->whereKey($locked->profile_id)->value('code'));
        });
    }
}
