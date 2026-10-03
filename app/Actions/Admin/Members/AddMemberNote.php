<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Models\AdminUser;
use App\Models\MemberNote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add an internal note to a member (A03 "Notes (append-only)"). Visible to admins only, never to
 * the member; there is no edit or delete. Needs `members.edit`. The audit row records that a
 * note was added (not its text — the note itself is the record).
 */
final class AddMemberNote
{
    public const MAX_LENGTH = 2000;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, User $member, string $body): MemberNote
    {
        Gate::forUser($admin)->authorize('members.edit');
        $body = trim($body);

        if ($body === '' || mb_strlen($body) > self::MAX_LENGTH) {
            throw ValidationException::withMessages(['noteBody' => __('Write a note of up to :max characters.', ['max' => self::MAX_LENGTH])]);
        }

        return DB::transaction(function () use ($admin, $member, $body): MemberNote {
            $note = new MemberNote;
            $note->forceFill([
                'user_id' => $member->id,
                'admin_user_id' => $admin->id,
                'admin_label' => $admin->email,
                'body' => $body,
            ])->save();

            $this->audit->record('members.note_added', $member, after: ['note' => $note->id], actor: $admin,
                subjectLabel: $member->profile()->withTrashed()->value('code'));

            return $note;
        });
    }
}
