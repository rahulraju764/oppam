<?php

declare(strict_types=1);

namespace App\Actions\Admin\Chat;

use App\Models\AdminUser;
use App\Models\BreakGlassGrant;
use App\Models\Conversation;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * End a break-glass read before its time runs out (PRD A13). Only the admin the grant belongs to
 * (anyone else: 404). Audited ("chat.break_glass.ended"). Ending an already ended or expired grant
 * changes nothing.
 */
final class EndBreakGlass
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return bool whether the grant was running and is ended now */
    public function handle(AdminUser $admin, BreakGlassGrant $grant): bool
    {
        if ($grant->admin_user_id !== $admin->id) {
            throw new NotFoundHttpException;
        }

        return DB::transaction(function () use ($admin, $grant): bool {
            $locked = BreakGlassGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                return false;
            }

            $locked->ended_at = now();
            $locked->save();

            $thread = Conversation::query()->find($locked->conversation_id);
            $this->audit->record('chat.break_glass.ended', $thread, after: ['grant_id' => $locked->id],
                reason: $locked->reason, actor: $admin, subjectLabel: $thread?->memberCodesLabel());

            return true;
        });
    }
}
