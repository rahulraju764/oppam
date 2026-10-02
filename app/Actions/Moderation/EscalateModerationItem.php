<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Enums\ModerationStatus;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\ModerationItem;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pass an item to a super admin (A04 "escalate"): OPEN → ESCALATED with the moderator's reason,
 * claim released. It leaves the normal queue and appears under Escalations. Audited.
 */
final class EscalateModerationItem
{
    use DecidesModerationItems;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, ModerationItem $item, string $note): void
    {
        $this->authorizeDecision($admin, $item);

        $note = trim($note);
        if ($note === '' || mb_strlen($note) > 1000) {
            throw ValidationException::withMessages(['note' => __('Say why you are escalating (up to 1000 characters).')]);
        }

        if ($item->status === ModerationStatus::Escalated) {
            throw ModerationItemUnavailable::alreadyDecided();
        }

        DB::transaction(function () use ($admin, $item, $note): void {
            $this->holdClaim($admin, $item);

            $item->forceFill([
                'status' => ModerationStatus::Escalated,
                'reason_note' => $note,
                'claimed_by_admin_id' => null,
                'claimed_until' => null,
            ])->save();

            $this->audit->record('moderation.escalated', $item, reason: $note, actor: $admin, subjectLabel: $item->type->value);
        });
        ModerationQueueChanged::dispatch($item->type);
    }
}
