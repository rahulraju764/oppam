<?php

declare(strict_types=1);

namespace App\Actions\Admin\Members;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Data\Admin\BulkResult;
use App\Enums\MemberBulkAction as Action;
use App\Enums\UserRole;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\Account\AdminMessage;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A03 bulk actions with a typed reason: suspend, reactivate, or email a message — never delete.
 * At most MAX members per run. Each member goes through the single-member Action (its own rules,
 * transaction and audit row); members in the wrong state are skipped and counted. One summary
 * audit row lists the member codes.
 */
final class BulkMemberAction
{
    use ChangesMemberState;

    public const MAX = 200;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SuspendMember $suspend,
        private readonly ReactivateMember $reactivate,
    ) {}

    /**
     * @param  list<string>  $userIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, Action $action, array $userIds, string $reason, string $subject = '', string $message = ''): BulkResult
    {
        Gate::forUser($admin)->authorize($action->permission());
        $reason = $this->validatedReason($reason);
        $userIds = array_values(array_unique(array_filter($userIds, 'is_string')));

        if ($userIds === [] || count($userIds) > self::MAX) {
            throw ValidationException::withMessages(['selected' => __('Select between 1 and :max members.', ['max' => self::MAX])]);
        }

        if ($action === Action::Notify) {
            [$subject, $message] = [trim($subject), trim($message)];
            if (mb_strlen($subject) < 3 || mb_strlen($subject) > 150) {
                throw ValidationException::withMessages(['bulkSubject' => __('Write a subject of 3 to 150 characters.')]);
            }
            if (mb_strlen($message) < 10 || mb_strlen($message) > 2000) {
                throw ValidationException::withMessages(['bulkMessage' => __('Write a message of 10 to 2000 characters.')]);
            }
        }

        // Members only (never a broker id slipped into the selection); deleted accounts are never included.
        $members = User::query()->whereKey($userIds)->where('role', UserRole::Member->value)->get();
        $done = 0;

        foreach ($members as $member) {
            try {
                match ($action) {
                    Action::Suspend => $this->suspend->handle($admin, $member, $reason),
                    Action::Activate => $this->reactivate->handle($admin, $member, $reason),
                    Action::Notify => $this->message($admin, $member, $subject, $message, $reason),
                };
                $done++;
            } catch (MemberStateConflict) {
                // Wrong state for this action (already suspended, no verified email…): skipped.
            }
        }

        $codes = Profile::withTrashed()->whereIn('user_id', $members->pluck('id'))->pluck('code')->all();

        $this->audit->record('members.bulk_'.strtolower($action->value), null, after: array_filter([
            'selected' => count($userIds),
            'done' => $done,
            'members' => $codes,
            'subject' => $action === Action::Notify ? $subject : null,
        ]), reason: $reason, actor: $admin);

        return new BulkResult($done, count($userIds) - $done);
    }

    /** @throws MemberStateConflict an inactive account or one without a verified email (skipped) */
    private function message(AdminUser $admin, User $member, string $subject, string $message, string $reason): void
    {
        if (! $member->isActive() || ! $member->canReceiveAccountMail()) {
            throw MemberStateConflict::notActive();
        }

        $member->notify(new AdminMessage($subject, $message));
        $this->audit->record('members.message_sent', $member, after: ['subject' => $subject], reason: $reason, actor: $admin,
            subjectLabel: Profile::withTrashed()->where('user_id', $member->id)->value('code'));
    }
}
