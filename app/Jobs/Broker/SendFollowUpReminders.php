<?php

declare(strict_types=1);

namespace App\Jobs\Broker;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\ManagedProfileScope;
use App\Enums\BrokerStatus;
use App\Enums\BureauPermission;
use App\Enums\BureauStaffStatus;
use App\Enums\ProfileOwnerType;
use App\Models\BrokerClientNote;
use App\Models\BrokerStaff;
use App\Models\Profile;
use App\Notifications\Broker\FollowUpDue;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * §11A B.15 "FollowUpDue", scheduled daily at 08:30 IST: every open client follow-up due today or
 * earlier (IST) counts for its note's author and the profile's assignee — each only while an ACTIVE
 * login of that bureau holding `client_notes.write`, on an ACTIVE bureau, with the (unclaimed,
 * not deleted) profile inside their ManagedProfileScope. One FollowUpDue per login per day — a
 * re-run the same day sends nothing twice (cache marker).
 */
final class SendFollowUpReminders implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(BureauGate $gate, ManagedProfileScope $scope): void
    {
        $today = now(config('oppam.display_timezone'))->format('Y-m-d');
        /** @var array<string, int> $counts user id => due follow-ups */
        $counts = [];
        /** @var array<string, BrokerStaff|null> $logins user id => eligible login (memo) */
        $logins = [];

        BrokerClientNote::query()
            ->whereNull('follow_up_done_at')
            ->where('follow_up_on', '<=', $today)
            ->whereHas('broker', fn ($q) => $q->where('status', BrokerStatus::Active->value))
            ->whereHas('profile', fn ($q) => $q->where('owner_type', ProfileOwnerType::BrokerManaged->value)->whereNull('claimed_at'))
            ->with(['profile:id,code,owner_type,managed_by_broker_id,assigned_staff_id,claimed_at', 'profile.assignedStaff:id,user_id'])
            ->lazyById(500)
            ->each(function (BrokerClientNote $note) use ($gate, $scope, &$counts, &$logins): void {
                $profile = $note->profile;
                if (! $profile instanceof Profile) {
                    return;
                }

                $recipients = array_unique(array_filter([$note->author_user_id, $profile->assignedStaff?->user_id]));

                foreach ($recipients as $userId) {
                    if (! array_key_exists($userId, $logins)) {
                        $logins[$userId] = $this->eligibleLogin($gate, (string) $userId);
                    }
                    $staff = $logins[$userId];

                    if ($staff !== null && $staff->broker_id === $note->broker_id && $scope->covers($staff, $profile)) {
                        $counts[$userId] = ($counts[$userId] ?? 0) + 1;
                    }
                }
            });

        foreach ($counts as $userId => $count) {
            $user = $logins[$userId]?->user;

            if ($user !== null && Cache::add('broker-followups:'.$userId.':'.$today, true, now()->addHours(36))) {
                $user->notify(new FollowUpDue($count));
            }
        }
    }

    private function eligibleLogin(BureauGate $gate, string $userId): ?BrokerStaff
    {
        $staff = BrokerStaff::query()->where('user_id', $userId)->where('status', BureauStaffStatus::Active)->with('user')->first();

        return $staff?->user !== null && $gate->allows($staff->user, BureauPermission::ClientNotesWrite) ? $staff : null;
    }
}
