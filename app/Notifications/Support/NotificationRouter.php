<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Enums\NotificationEvent;
use App\Enums\SettingKey;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;

/**
 * Which channels a member notification goes out on (PRD M08 "Implementation"): in-app (InAppChannel:
 * the row, then the live broadcast), email, SMS — each from the member's `notification_preferences`
 * row for the event, else the event's default. Mandatory events (transactional / safety, R-M08-3)
 * always use their defaults: the member can't switch one off.
 * Email needs a verified address (canReceiveAccountMail), SMS a verified phone. SMS in quiet hours
 * (M08: one window for everyone, A15 `notifications.quiet_from` / `quiet_until`, 22:00–07:00 IST by
 * default) waits until they end (smsDelay) — mandatory events too: nobody is texted at night.
 */
final class NotificationRouter
{
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

    /**
     * Until when an SMS sent now must wait: the end of quiet hours (IST) when now is inside them,
     * else null. The window may wrap midnight (22 → 7) or not (13 → 15); the same start and end
     * hour means no quiet hours.
     */
    public function smsDelay(?CarbonImmutable $now = null): ?CarbonImmutable
    {
        $from = Settings::int(SettingKey::NotificationsQuietFrom);
        $until = Settings::int(SettingKey::NotificationsQuietUntil);
        $local = ($now ?? CarbonImmutable::now())->setTimezone((string) config('oppam.display_timezone'));
        $hour = $local->hour;

        if ($from === $until) {
            return null;   // never "always quiet"
        }

        if ($from < $until) {
            return $hour >= $from && $hour < $until ? $local->setTime($until, 0) : null;
        }

        if ($hour >= $from) {
            return $local->addDay()->setTime($until, 0);
        }

        return $hour < $until ? $local->setTime($until, 0) : null;
    }
}
