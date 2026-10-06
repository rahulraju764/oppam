<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Enums\NotificationEvent;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Which channels a member notification goes out on (PRD M08 "Implementation"): in-app (InAppChannel:
 * the row, then the live broadcast), email, SMS — each from the member's `notification_preferences`
 * row for the event, else the event's default. Mandatory events (transactional / safety, R-M08-3)
 * always use their defaults: the member can't switch one off.
 * Email needs a verified address (canReceiveAccountMail), SMS a verified phone. SMS in quiet hours (22:00–07:00 IST) waits until
 * 07:00 (smsDelay).
 */
final class NotificationRouter
{
    public const QUIET_FROM = 22;

    public const QUIET_UNTIL = 7;

    /** @return list<string> Laravel channel names / classes */
    public function channels(object $notifiable, NotificationEvent $event): array
    {
        if (! $notifiable instanceof User) {
            return [];
        }

        $preference = $notifiable->relationLoaded('notificationPreferences')
            ? $notifiable->notificationPreferences->first(fn (NotificationPreference $p): bool => $p->event === $event->value)
            : NotificationPreference::query()->where('user_id', $notifiable->id)->where('event', $event->value)->first();

        // Mandatory = always exactly the event's own channels: an "off" can't remove one, and a
        // channel the event doesn't use (e.g. SMS for account_status) is never added.
        $wants = fn (string $channel, bool $default): bool => ($event->isMandatory() || $preference === null)
            ? $default
            : (bool) $preference->{$channel};

        $channels = [];
        if ($wants('in_app', $event->defaultInApp())) {
            $channels[] = InAppChannel::class;
        }
        if ($notifiable->canReceiveAccountMail() && $wants('email', $event->defaultEmail())) {
            $channels[] = 'mail';
        }
        if ($notifiable->hasVerifiedPhone() && filled($notifiable->phone) && $wants('sms', $event->defaultSms())) {
            $channels[] = SmsChannel::class;
        }

        return $channels;
    }

    /** How long an SMS sent now must wait: until 07:00 IST during quiet hours, else not at all. */
    public function smsDelay(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $local = ($now ?? CarbonImmutable::now())->setTimezone((string) config('oppam.display_timezone'));

        if ($local->hour >= self::QUIET_FROM) {
            return $local->addDay()->setTime(self::QUIET_UNTIL, 0);
        }
        if ($local->hour < self::QUIET_UNTIL) {
            return $local->setTime(self::QUIET_UNTIL, 0);
        }

        return null;
    }
}
