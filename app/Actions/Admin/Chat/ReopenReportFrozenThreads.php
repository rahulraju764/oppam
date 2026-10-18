<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Domain\Chat\ChatFreezer;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Lift the automatic freeze after reports (PRD M09, decisions 2026-10-16) for one member: every
 * thread of theirs frozen with reason REPORTS opens again at once — including threads with members
 * who never reported anything. Threads a moderator froze stay frozen. Needs `chat.freeze` and a
 * typed reason (5 – 500); audited ("chat.report_freeze_lifted"); both screens of each thread follow
 * live (ConversationFrozen). Dismissing the reports does the same automatically (DecideMessageFlag).
 */
final class ReopenReportFrozenThreads
{
    public function __construct(
        private readonly ChatFreezer $freezer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return int how many threads opened
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Profile $member, string $reason): int
    {
        Gate::forUser($admin)->authorize('chat.freeze');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']],
            [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($admin, $member, $reason): int {
            $opened = $this->freezer->unfreezeReportFrozen($member->id);

            if ($opened > 0) {
                $this->audit->record('chat.report_freeze_lifted', $member, after: ['threads_opened' => $opened],
                    reason: $reason, actor: $admin, subjectLabel: $member->code);
            }

            return $opened;
        });
    }
}
