<?php

declare(strict_types=1);

namespace App\Actions\Admin\Safety;

use App\Actions\Admin\Members\Concerns\ChangesMemberState;
use App\Domain\Chat\ChatFreezer;
use App\Domain\Safety\BanList;
use App\Enums\BannedIdentifierType;
use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use App\Enums\ModerationHold;
use App\Enums\ProfileStatus;
use App\Enums\UserStatus;
use App\Exceptions\Admin\MemberStateConflict;
use App\Models\AdminUser;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Media;
use App\Models\Profile;
use App\Models\TrustedDevice;
use App\Models\User;
use App\Notifications\Account\AccountSuspended;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Ban a member (PRD A10 outcome "ban (email/phone/device/pHash blocklist)", decisions 2026-10-23):
 * like a suspension — the account can't sign in, every session ends (live ForceLogout), the
 * profile becomes SUSPENDED, running plans pause, waiting moderation items leave the queues —
 * and also: the account is BANNED, every OPEN thread of the member freezes (MODERATION), and their
 * mobile, email, trusted-device hashes and photo pHashes go on the blocklist (BanList) so a new
 * account can't come back with them. Needs `safety.ban`; typed reason; audited. The member gets
 * the same neutral email as a suspension. Called by DecideAbuseCase inside its transaction.
 */
final class BanMember
{
    use ChangesMemberState;

    public function __construct(
        private readonly BanList $bans,
        private readonly ChatFreezer $freezer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, int> what was done (counts only)
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws MemberStateConflict already banned or deleted
     */
    public function handle(AdminUser $admin, User $member, string $reason, ?string $caseId = null): array
    {
        Gate::forUser($admin)->authorize('safety.ban');
        $reason = $this->validatedReason($reason);

        [$banned, $done] = DB::transaction(function () use ($admin, $member, $reason, $caseId): array {
            $member = $this->lockMember($member);
            if (! in_array($member->status, [UserStatus::Active, UserStatus::Suspended], true) || $member->trashed()) {
                throw MemberStateConflict::notActive();
            }

            $profile = $this->lockProfile($member);
            $before = ['status' => $member->status->value, 'profile_status' => $profile?->status->value];

            $this->revokeSessions($member);
            $member->forceFill(['status' => UserStatus::Banned, 'suspended_at' => $member->suspended_at ?? now()])->save();

            $done = ['plans_paused' => 0, 'moderation_items_held' => 0, 'threads_frozen' => 0, 'identifiers_banned' => 0];
            if ($profile !== null) {
                if ($profile->status !== ProfileStatus::Suspended) {
                    $this->rememberStatus($profile);
                    $profile->status = ProfileStatus::Suspended;
                    $profile->save();
                }
                $done['plans_paused'] = $this->pauseSubscriptions($profile);
                $done['moderation_items_held'] = $this->holdModerationItems($profile, ModerationHold::MemberSuspended);
                $done['threads_frozen'] = $this->freezeThreads($profile);
            }
            $done['identifiers_banned'] = $this->blocklist($admin, $member, $profile, $caseId);

            $this->audit->record('members.banned', $member, $before, ['status' => UserStatus::Banned->value, ...$done],
                reason: $reason, actor: $admin, subjectLabel: $profile?->code);

            return [$member, $done];
        });

        /** @var User $banned */
        if ($banned->canReceiveAccountMail()) {
            $banned->notify(new AccountSuspended);
        }

        return $done;
    }

    private function freezeThreads(Profile $profile): int
    {
        $ids = ConversationParticipant::query()->where('profile_id', $profile->id)->pluck('conversation_id')->all();
        $frozen = 0;
        foreach (Conversation::query()->whereKey($ids)->whereIn('status', [ConversationStatus::Open->value, ConversationStatus::Frozen->value])->lockForUpdate()->get() as $thread) {
            $frozen += $this->freezer->freeze($thread, FreezeReason::Moderation) ? 1 : 0;
        }

        return $frozen;
    }

    private function blocklist(AdminUser $admin, User $member, ?Profile $profile, ?string $caseId): int
    {
        $added = 0;
        $add = function (BannedIdentifierType $type, ?string $value) use (&$added, $admin, $profile, $caseId): void {
            if ($value !== null && $this->bans->add($type, $value, $profile?->id, $caseId, $admin->id)) {
                $added++;
            }
        };

        $add(BannedIdentifierType::Phone, $member->phone);
        $add(BannedIdentifierType::Email, $member->email);
        foreach (TrustedDevice::query()->where('user_id', $member->id)->pluck('device_hash') as $device) {
            $add(BannedIdentifierType::Device, (string) $device);
        }
        if ($profile !== null) {
            foreach (Media::query()->where('model_type', $profile->getMorphClass())->where('model_id', $profile->id)
                ->where('collection_name', Profile::PHOTOS)->whereNotNull('phash')->pluck('phash') as $phash) {
                $add(BannedIdentifierType::Phash, (string) $phash);
            }
        }

        return $added;
    }
}
