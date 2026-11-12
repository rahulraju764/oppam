<?php

declare(strict_types=1);

namespace App\Jobs\Comms;

use App\Domain\Comms\Journeys\InactiveMatchesAudience;
use App\Domain\Comms\Journeys\JourneyAudience;
use App\Domain\Comms\Journeys\JourneyCandidate;
use App\Domain\Comms\Journeys\PendingInterestAudience;
use App\Domain\Comms\Journeys\WinBackAudience;
use App\Domain\Comms\Journeys\WizardIncompleteAudience;
use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use App\Enums\JourneySendStatus;
use App\Enums\SettingKey;
use App\Models\AutomatedJourney;
use App\Models\Block;
use App\Models\JourneySend;
use App\Models\User;
use App\Notifications\Comms\JourneyReminder;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Throwable;

/**
 * A14 automated journeys (PRD §11 A14, P8.3b2), daily at 10:30 IST (after the 10:00 plan-expiry
 * reminders). For every journey an admin switched on, in priority order (JourneyKey::byPriority),
 * and every day offset it is configured with, the journey's audience names who qualifies; then
 * per member:
 *  - at most ONE journey message a member a day (a SENT row for today stops the rest);
 *  - a step goes out once per subject: the ledger row (unique) is written before the message is
 *    queued — a second run, the next day's catch-up window, a retried job all find it;
 *  - the other member in a blocked pair → skipped (R-M08-1, also re-checked by the notification);
 *  - the journey's channel can't reach the member, or the member switched this reminder off there →
 *    skipped and recorded, never sent on another channel;
 *  - queued like every member notification: an SMS waits out quiet hours (MemberNotification::withDelay).
 * One member's failure is recorded (FAILED) and the run goes on. Ledger rows are kept `journeys.ledger_retention_days` (A15).
 */
final class RunJourneys implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private const CHUNK = 500;

    /** Below the queue's retry_after (960 s), so a long run is never handed out twice. */
    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $now = CarbonImmutable::now();
        $today = $now->setTimezone((string) config('oppam.display_timezone'))->toDateString();

        JourneySend::query()->where('created_at', '<', $now->subDays(Settings::int(SettingKey::JourneysLedgerRetentionDays)))->delete();

        $journeys = AutomatedJourney::query()->where('is_active', true)->get()->keyBy(fn (AutomatedJourney $j): string => $j->key->value);

        foreach (JourneyKey::byPriority() as $key) {
            $journey = $journeys->get($key->value);
            if ($journey === null) {
                continue;
            }

            $audience = $this->audience($key);
            foreach ($journey->offsets as $offset) {
                $chunk = [];
                foreach ($audience->candidates($offset, $now) as $candidate) {
                    $chunk[] = $candidate;
                    if (count($chunk) === self::CHUNK) {
                        $this->sendChunk($journey, $offset, $today, $chunk);
                        $chunk = [];
                    }
                }
                if ($chunk !== []) {
                    $this->sendChunk($journey, $offset, $today, $chunk);
                }
            }
        }
    }

    /** @param list<JourneyCandidate> $candidates */
    private function sendChunk(AutomatedJourney $journey, int $offset, string $today, array $candidates): void
    {
        $userIds = array_values(array_unique(array_map(fn (JourneyCandidate $c): string => $c->userId, $candidates)));

        /** @var Collection<string, User> $users */
        $users = User::query()->whereKey($userIds)->with(['profile', 'notificationPreferences'])->get()->keyBy('id');
        /** @var array<string, true> $doneToday */
        $doneToday = JourneySend::query()->whereIn('user_id', $userIds)->where('sent_on', $today)
            ->where('status', JourneySendStatus::Sent->value)->pluck('user_id')->flip()->map(fn (): bool => true)->all();

        $blocked = $this->blockedPairs($candidates, $users);

        foreach ($candidates as $candidate) {
            $user = $users->get($candidate->userId);
            if ($user === null || isset($doneToday[$user->id])) {
                continue;
            }

            if ($this->send($journey, $offset, $today, $candidate, $user, $blocked)) {
                $doneToday[$user->id] = true;
            }
        }
    }

    /**
     * Blocked pairs among this chunk's recipients and the other members their steps are about (R-M08-1),
     * in ONE query — keyed "profileA|profileB" both ways.
     *
     * @param  list<JourneyCandidate>  $candidates
     * @param  Collection<string, User>  $users
     * @return array<string, true>
     */
    private function blockedPairs(array $candidates, Collection $users): array
    {
        $recipients = [];
        $actors = [];
        foreach ($candidates as $candidate) {
            $profileId = $users->get($candidate->userId)?->profile?->id;
            if ($candidate->actorProfileId !== null && $profileId !== null) {
                $recipients[$profileId] = true;
                $actors[$candidate->actorProfileId] = true;
            }
        }
        if ($actors === []) {
            return [];
        }

        $pairs = [];
        $ids = array_keys($recipients + $actors);
        foreach (Block::query()->whereIn('blocker_profile_id', $ids)->whereIn('blocked_profile_id', $ids)->get(['blocker_profile_id', 'blocked_profile_id']) as $block) {
            $pairs[$block->blocker_profile_id.'|'.$block->blocked_profile_id] = true;
            $pairs[$block->blocked_profile_id.'|'.$block->blocker_profile_id] = true;
        }

        return $pairs;
    }

    /**
     * @param  array<string, true>  $blocked  from blockedPairs()
     * @return bool true when a message was queued
     */
    private function send(AutomatedJourney $journey, int $offset, string $today, JourneyCandidate $candidate, User $user, array $blocked): bool
    {
        $message = new JourneyReminder($journey->key, $journey->channel, $candidate->count, $candidate->actorProfileId);

        $status = match (true) {
            $candidate->actorProfileId !== null && $user->profile !== null
                && isset($blocked[$user->profile->id.'|'.$candidate->actorProfileId]) => JourneySendStatus::SkippedUnreachable,
            ! $this->reachable($user, $journey->channel) => JourneySendStatus::SkippedUnreachable,
            $message->via($user) === [] => JourneySendStatus::SkippedOptedOut,
            default => JourneySendStatus::Sent,
        };

        // The claim: a step + subject is written once; a row already there means it was handled.
        $claimed = JourneySend::query()->insertOrIgnore([
            'journey_key' => $journey->key->value,
            'user_id' => $user->id,
            'step' => $offset,
            'subject_ref' => $candidate->subjectRef,
            'sent_on' => $today,
            'status' => $status->value,
            'created_at' => CarbonImmutable::now(),
        ]) === 1;

        if (! $claimed || $status !== JourneySendStatus::Sent) {
            return false;
        }

        try {
            $user->notify($message);
        } catch (Throwable $e) {
            report($e);
            JourneySend::query()->where('journey_key', $journey->key->value)->where('user_id', $user->id)
                ->where('step', $offset)->where('subject_ref', $candidate->subjectRef)
                ->update(['status' => JourneySendStatus::Failed->value]);

            return false;
        }

        return true;
    }

    /** The channel can reach the member at all (as NotificationRouter requires), told apart from an opt-out. */
    private function reachable(User $user, CommsChannel $channel): bool
    {
        return match ($channel) {
            CommsChannel::Email => $user->canReceiveAccountMail(),
            CommsChannel::Sms => $user->hasVerifiedPhone() && filled($user->phone),
            CommsChannel::InApp => true,
        };
    }

    private function audience(JourneyKey $key): JourneyAudience
    {
        return app(match ($key) {
            JourneyKey::PendingInterest => PendingInterestAudience::class,
            JourneyKey::WizardIncomplete => WizardIncompleteAudience::class,
            JourneyKey::InactiveMatches => InactiveMatchesAudience::class,
            JourneyKey::WinBack => WinBackAudience::class,
        });
    }
}
