<?php

declare(strict_types=1);

namespace App\Jobs\Engagement;

use App\Domain\Engagement\EngagementSignals;
use App\Enums\EngagementSignalStatus;
use App\Enums\EngagementSignalType;
use App\Enums\InterestStatus;
use App\Enums\MessageType;
use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\EngagementSignal;
use App\Models\Interest;
use App\Models\Message;
use App\Support\Facades\Settings;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The A13 monitors that need a look back over time (PRD A13 "Engagement abuse monitors"), hourly:
 *  - interest spam: at least `signals.interest_spam_min_sent` interests sent within
 *    `signals.interest_spam_window_days`, at most `signals.interest_spam_max_accept_pct` % accepted;
 *  - new accounts messaging many members: an account younger than `signals.new_account_days` that
 *    wrote in at least `signals.new_account_threads_per_day` conversations in the last 24 hours.
 * A member who already had a signal of that type within the window (open or decided) isn't raised
 * again — a dismissed signal stays dismissed until a new window. Grouped queries on indexed windows
 * (interests.sent_at, messages.created_at); the rest of the monitors are raised as things happen.
 */
final class DetectEngagementSignals implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const MAX_PER_RUN = 200;

    public int $timeout = 300;

    public int $tries = 3;

    public int $backoff = 120;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(EngagementSignals $signals): void
    {
        $this->interestSpam($signals);
        $this->newAccountMessaging($signals);
    }

    private function interestSpam(EngagementSignals $signals): void
    {
        $days = Settings::int(SettingKey::SignalsInterestSpamWindowDays);
        $minSent = Settings::int(SettingKey::SignalsInterestSpamMinSent);
        $maxPct = Settings::int(SettingKey::SignalsInterestSpamMaxAcceptPct);
        $since = now()->subDays($days);

        $rows = Interest::query()
            ->where('sent_at', '>=', $since)
            ->selectRaw('from_profile_id, count(*) as sent, sum(case when status = ? then 1 else 0 end) as accepted', [InterestStatus::Accepted->value])
            ->groupBy('from_profile_id')
            ->havingRaw('count(*) >= ?', [$minSent])
            ->havingRaw('sum(case when status = ? then 1 else 0 end) * 100 <= ? * count(*)', [InterestStatus::Accepted->value, $maxPct])
            ->limit(self::MAX_PER_RUN)->toBase()->get();

        foreach ($this->notRecentlyRaised($rows->pluck('from_profile_id')->all(), EngagementSignalType::InterestSpam, $since) as $profileId) {
            $row = $rows->firstWhere('from_profile_id', $profileId);
            $signals->raise($profileId, EngagementSignalType::InterestSpam,
                ['sent' => (int) $row->sent, 'accepted' => (int) $row->accepted, 'window_days' => $days]);
        }
    }

    private function newAccountMessaging(EngagementSignals $signals): void
    {
        $minThreads = Settings::int(SettingKey::SignalsNewAccountThreadsPerDay);
        $since = now()->subDay();

        $rows = Message::query()
            ->join('profiles', 'profiles.id', '=', 'messages.sender_profile_id')
            ->join('users', 'users.id', '=', 'profiles.user_id')
            ->where('messages.created_at', '>=', $since)
            ->where('messages.type', '!=', MessageType::System->value)
            ->where('users.role', UserRole::Member->value)
            ->where('users.created_at', '>=', now()->subDays(Settings::int(SettingKey::SignalsNewAccountDays)))
            ->selectRaw('messages.sender_profile_id as profile_id, count(distinct messages.conversation_id) as threads')
            ->groupBy('messages.sender_profile_id')
            ->having('threads', '>=', $minThreads)
            ->limit(self::MAX_PER_RUN)->toBase()->get();

        foreach ($this->notRecentlyRaised($rows->pluck('profile_id')->all(), EngagementSignalType::NewAccountMassMessaging, $since) as $profileId) {
            $signals->raise($profileId, EngagementSignalType::NewAccountMassMessaging,
                ['threads_24h' => (int) $rows->firstWhere('profile_id', $profileId)->threads]);
        }
    }

    /**
     * @param  list<string>  $profileIds
     * @return list<string> those without a signal of this type since $since
     */
    private function notRecentlyRaised(array $profileIds, EngagementSignalType $type, DateTimeInterface $since): array
    {
        if ($profileIds === []) {
            return [];
        }

        $raised = EngagementSignal::query()->whereIn('profile_id', $profileIds)->where('type', $type->value)
            ->where(fn ($q) => $q->where('created_at', '>=', $since)->orWhere('status', EngagementSignalStatus::Open->value))
            ->pluck('profile_id')->all();

        return array_values(array_diff($profileIds, $raised));
    }
}
