<?php

declare(strict_types=1);

namespace App\Jobs\Comms;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use App\Enums\ProfileStatus;
use App\Enums\UserRole;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\User;
use App\Notifications\Comms\CampaignMessage;
use App\Notifications\Support\NotificationRouter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sends one minute's worth of an A14 campaign (P8.3b1), then queues the next batch a minute later
 * until every frozen recipient is done — that is the throttle (`throttle_per_minute`).
 *  - ONE chain per campaign: every batch carries the campaign's current `batch_token`; scheduling a
 *    batch (schedule()) issues a new token, so any older queued batch — a lost chain that turns up
 *    late, the batch held through quiet hours after a restart — exits without sending;
 *  - only while the campaign is SENDING; cancellation and the token are re-read every 50 members;
 *  - an SMS campaign never texts in quiet hours (A15 window, NotificationRouter::smsDelay), checked
 *    at batch start and again before every text (a batch that reaches the window stops there): the
 *    next batch is scheduled for the window's end;
 *  - each member is re-checked at send: account ACTIVE (not closed / soft-deleted), profile ACTIVE
 *    (else SKIPPED inactive); the router's channels for "Offers and announcements" include this
 *    channel (else SKIPPED opted out) — an opt-out made after submit still wins;
 *  - a recipient leaves PENDING exactly once (conditional update), so no member is sent twice; any
 *    failure on one member marks that member FAILED and the batch goes on (no automatic resend).
 * StartDueCampaigns restarts a chain that is overdue.
 */
final class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const RECHECK_EVERY = 50;

    /** Up to A15 campaigns.max_per_minute (1,000) sends in one batch, with slow providers. */
    public int $timeout = 900;

    public int $tries = 3;

    public function __construct(
        public readonly string $campaignId,
        public readonly string $token,
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Makes this campaign's next batch the only one allowed to run, and queues it (after the
     * caller's transaction commits). $at = when it may run (null = now).
     */
    public static function schedule(Campaign $campaign, ?DateTimeInterface $at = null): void
    {
        $token = (string) Str::ulid();
        // Stored in UTC: a datetime column keeps no offset, and smsDelay() returns an IST time.
        $due = $at !== null ? CarbonImmutable::instance($at)->utc() : CarbonImmutable::now()->utc();
        $campaign->forceFill(['batch_token' => $token, 'next_batch_at' => $due])->save();

        $job = new self($campaign->id, $token);
        if ($at !== null) {
            $job->delay($at);
        }
        DB::afterCommit(fn () => dispatch($job));
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('campaign:'.$this->campaignId))->dontRelease()->expireAfter($this->timeout + 60)];
    }

    public function handle(NotificationRouter $router): void
    {
        $campaign = $this->current();
        if ($campaign === null) {
            return;
        }

        if ($campaign->channel === CommsChannel::Sms && ($until = $router->smsDelay()) !== null) {
            DB::transaction(fn () => self::schedule($campaign, $until));

            return;
        }

        $startedAt = microtime(true);
        $batch = CampaignRecipient::query()
            ->where('campaign_id', $campaign->id)
            ->where('status', CampaignRecipientStatus::Pending->value)
            ->orderBy('id')
            ->limit(max(1, $campaign->throttle_per_minute))
            ->with(['user' => fn ($q) => $q->withTrashed()->with('profile')])
            ->get();

        $isSms = $campaign->channel === CommsChannel::Sms;
        foreach ($batch as $i => $recipient) {
            if ($i > 0 && $i % self::RECHECK_EVERY === 0 && ! $this->stillCurrent()) {
                return;
            }
            // A batch can run for minutes: quiet hours are re-checked before every text, not only at the start.
            if ($isSms && ($until = $router->smsDelay()) !== null) {
                $this->holdUntil($campaign, $until);

                return;
            }
            $this->deliver($campaign, $recipient);
        }

        $morePending = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->where('status', CampaignRecipientStatus::Pending->value)->exists();

        DB::transaction(function () use ($campaign, $morePending, $startedAt): void {
            $row = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== CampaignStatus::Sending || $row->batch_token !== $this->token) {
                return;   // cancelled, or another chain took over while this batch ran
            }

            if ($morePending) {
                self::schedule($row, now()->addSeconds(max(1, 60 - (int) (microtime(true) - $startedAt))));

                return;
            }

            $row->forceFill(['status' => CampaignStatus::Completed, 'completed_at' => now(), 'batch_token' => null, 'next_batch_at' => null])->save();
        });
    }

    /** The campaign when it is SENDING and this batch holds its token, else null. */
    private function current(): ?Campaign
    {
        $campaign = Campaign::query()->find($this->campaignId);

        return $campaign !== null && $campaign->status === CampaignStatus::Sending && $campaign->batch_token === $this->token
            ? $campaign : null;
    }

    /** Quiet hours began mid-batch: the rest stay PENDING and this chain resumes at the window's end. */
    private function holdUntil(Campaign $campaign, CarbonImmutable $until): void
    {
        DB::transaction(function () use ($campaign, $until): void {
            $row = Campaign::query()->whereKey($campaign->id)->lockForUpdate()->first();
            if ($row === null || $row->status !== CampaignStatus::Sending || $row->batch_token !== $this->token) {
                return;   // cancelled, or another chain took over
            }
            self::schedule($row, $until);
        });
    }

    /**
     * Still SENDING with this batch's token. While it is, next_batch_at is moved to now, so a long batch
     * (slow provider, up to 1,000 members) is never taken for a stalled chain by StartDueCampaigns.
     */
    private function stillCurrent(): bool
    {
        $current = Campaign::query()->whereKey($this->campaignId)
            ->where('status', CampaignStatus::Sending->value)->where('batch_token', $this->token)->exists();

        if ($current) {
            Campaign::query()->whereKey($this->campaignId)->where('batch_token', $this->token)
                ->update(['next_batch_at' => CarbonImmutable::now()->utc()]);
        }

        return $current;
    }

    private function deliver(Campaign $campaign, CampaignRecipient $recipient): void
    {
        try {
            $user = $recipient->user;

            if (! $this->stillActive($user)) {
                $this->settle($recipient, CampaignRecipientStatus::Skipped, CampaignSkipReason::Inactive);

                return;
            }

            if (! $this->reachable($user, $campaign->channel)) {
                $this->settle($recipient, CampaignRecipientStatus::Skipped, CampaignSkipReason::Unreachable);

                return;
            }

            $message = new CampaignMessage($campaign, $recipient);
            if ($message->via($user) === []) {
                $this->settle($recipient, CampaignRecipientStatus::Skipped, CampaignSkipReason::OptedOut);

                return;
            }

            if (! $this->settle($recipient, CampaignRecipientStatus::Sent)) {
                return;   // already handled (a retried batch)
            }

            Notification::sendNow($user, $message);
        } catch (Throwable $e) {
            report($e);
            // One member's failure never holds up the rest: PENDING (failed before the claim) or SENT → FAILED.
            CampaignRecipient::query()->whereKey($recipient->id)
                ->whereIn('status', [CampaignRecipientStatus::Pending->value, CampaignRecipientStatus::Sent->value])
                ->update(['status' => CampaignRecipientStatus::Failed->value]);
        }
    }

    /** @phpstan-assert-if-true User $user */
    private function stillActive(?User $user): bool
    {
        return $user !== null
            && ! $user->trashed()
            && $user->role === UserRole::Member
            && $user->isActive()
            && $user->profile !== null
            && $user->profile->status === ProfileStatus::Active;
    }

    /** The same reachability the router requires (NotificationRouter::channels), told apart from an opt-out. */
    private function reachable(User $user, CommsChannel $channel): bool
    {
        return match ($channel) {
            CommsChannel::Email => $user->canReceiveAccountMail(),
            CommsChannel::Sms => $user->hasVerifiedPhone() && filled($user->phone),
            default => true,
        };
    }

    /** Moves the row out of PENDING (once); false when it had already left. */
    private function settle(CampaignRecipient $recipient, CampaignRecipientStatus $status, ?CampaignSkipReason $reason = null): bool
    {
        return CampaignRecipient::query()->whereKey($recipient->id)
            ->where('status', CampaignRecipientStatus::Pending->value)
            ->update([
                'status' => $status->value,
                'skip_reason' => $reason?->value,
                'sent_at' => $status === CampaignRecipientStatus::Sent ? now() : null,
            ]) === 1;
    }
}
