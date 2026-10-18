<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Data\Chat\FlagContextData;
use App\Models\AdminUser;
use App\Models\MessageFlag;
use App\Models\Profile;
use App\Queries\Chat\FlagContext;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Reveal a flagged message to a moderator (PRD A13 "revealed by an audited click"): the flagged
 * message with at most three messages before and after it in the same thread (FlagContext) —
 * never the rest of the conversation. Needs `chat.view_flagged`; every reveal writes an audit row
 * (A13 acceptance: "every evidence reveal writes an audit row with reason" — the reason is the
 * flag itself: its rule, and the report when a member reported it).
 */
final class RevealFlaggedMessage
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FlagContext $context,
    ) {}

    /** @throws AuthorizationException */
    public function handle(AdminUser $admin, MessageFlag $flag): FlagContextData
    {
        Gate::forUser($admin)->authorize('chat.view_flagged');

        $context = $this->context->for($flag);

        $this->audit->record('chat.flag.revealed', $flag, after: [
            'message_id' => $flag->message_id,
            'rule' => $flag->rule->value,
            'report_id' => $flag->report_id,
            'context_messages' => count($context->before) + count($context->after),
        ], reason: $flag->rule->label(), actor: $admin,
            subjectLabel: $flag->sender_profile_id !== null ? Profile::withTrashed()->whereKey($flag->sender_profile_id)->value('code') : null);

        return $context;
    }
}
