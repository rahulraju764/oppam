<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Domain\Chat\ChatFreezer;
use App\Enums\FreezeReason;
use App\Models\AdminUser;
use App\Models\Conversation;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Freeze a chat thread, or open a frozen one again (R-M07-5, PRD A13) — e.g. after reviewing a
 * thread frozen automatically by reports. Needs `chat.freeze` and a typed reason (5 – 500);
 * audited ("chat.frozen" / "chat.unfrozen"). Both members' screens follow live
 * (ConversationFrozen). Nothing changes when the thread is already in that state (or CLOSED).
 */
final class SetConversationFrozen
{
    public function __construct(
        private readonly ChatFreezer $freezer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return bool whether the thread changed
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Conversation $thread, bool $frozen, string $reason): bool
    {
        Gate::forUser($admin)->authorize('chat.freeze');
        $reason = trim($reason);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:5', 'max:500']],
            [], ['reason' => __('reason')])->validate();

        return DB::transaction(function () use ($admin, $thread, $frozen, $reason): bool {
            $locked = Conversation::query()->whereKey($thread->id)->lockForUpdate()->firstOrFail();
            $before = $locked->status->value;

            $changed = $frozen ? $this->freezer->freeze($locked, FreezeReason::Moderation) : $this->freezer->unfreeze($locked);
            if ($changed) {
                $this->audit->record($frozen ? 'chat.frozen' : 'chat.unfrozen', $locked,
                    ['status' => $before], ['status' => $locked->status->value], reason: $reason, actor: $admin);
            }

            return $changed;
        });
    }
}
