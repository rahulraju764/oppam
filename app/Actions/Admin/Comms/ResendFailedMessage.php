<?php

declare(strict_types=1);

namespace App\Actions\Admin\Comms;

use App\Enums\CommsChannel;
use App\Exceptions\Admin\CommsRefused;
use App\Models\AdminUser;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Notifications\Support\MemberNotification;
use App\Notifications\Support\SmsChannel;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * A14 delivery log: send a failed or bounced message again (P8.3a, `campaigns.send`). Only rows
 * holding a member notification (auth / invitation mails and OTPs are never resendable — they
 * carry tokens or codes; the member asks again), and each row once (claimed under a row lock).
 * The resend is QUEUED like any notification, on that one channel only, so everything a first send
 * checks still applies: the member is active, not anonymised, the channel is still allowed by
 * their preferences, the pair isn't blocked (R-M08-1, MemberNotification::shouldSend — refused
 * here up front too) and an SMS waits for the end of quiet hours (withDelay). The new log row
 * points at the old one.
 */
final class ResendFailedMessage
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws CommsRefused
     */
    public function handle(AdminUser $admin, OutboundMessage $message): void
    {
        Gate::forUser($admin)->authorize('campaigns.send');

        DB::transaction(function () use ($admin, $message): void {
            $row = OutboundMessage::query()->whereKey($message->id)->lockForUpdate()->firstOrFail();
            if (! $row->isResendable() || $row->resent_at !== null) {
                throw CommsRefused::notResendable();
            }

            $notification = $this->notificationOf($row);
            $member = User::query()->find($row->user_id);
            $channel = $row->channel === CommsChannel::Email ? 'mail' : SmsChannel::class;

            if ($member === null || ! $member->isActive() || $member->anonymised_at !== null
                || ! in_array($channel, $notification->routedChannels($member), true)) {
                throw CommsRefused::memberGone();
            }
            if (! $notification->shouldSend($member, $channel)) {
                throw CommsRefused::blockedPair();
            }

            $notification->onlyChannel = $channel;
            $notification->resentFromId = $row->id;
            $row->forceFill(['resent_at' => now()])->save();

            DB::afterCommit(fn () => $member->notify($notification));

            $this->audit->record('comms.message_resent', $row, after: ['channel' => $row->channel->value, 'event' => $row->event],
                actor: $admin, subjectLabel: $row->recipient_masked);
        });
    }

    /** @throws CommsRefused */
    private function notificationOf(OutboundMessage $row): MemberNotification
    {
        try {
            $notification = unserialize((string) $row->payload);
        } catch (Throwable) {   // a model inside it is gone (ModelNotFoundException) — nothing to resend
            throw CommsRefused::memberGone();
        }

        if (! $notification instanceof MemberNotification) {
            throw CommsRefused::notResendable();
        }

        return $notification;
    }
}
