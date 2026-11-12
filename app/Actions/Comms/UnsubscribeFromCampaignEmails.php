<?php

declare(strict_types=1);

namespace App\Actions\Comms;

use App\Enums\NotificationEvent;
use App\Models\CampaignRecipient;
use App\Models\NotificationPreference;
use Illuminate\Support\Facades\DB;

/**
 * The unsubscribe link / RFC 8058 one-click in a campaign email (P8.3b1). The signed link is the
 * credential (the member may not be signed in). It switches off EMAIL for "Offers and
 * announcements" (`admin_announcement`) in the member's notification settings — the same switch
 * as /settings?tab=notifications, so later campaigns skip them (SegmentQuery at submit,
 * NotificationRouter at send). Other channels and events are untouched. Idempotent; the first time
 * is counted on the campaign (unsubscribed_at).
 */
final class UnsubscribeFromCampaignEmails
{
    public function handle(CampaignRecipient $recipient): void
    {
        DB::transaction(function () use ($recipient): void {
            $event = NotificationEvent::AdminAnnouncement;
            $row = NotificationPreference::query()->where('user_id', $recipient->user_id)->where('event', $event->value)->lockForUpdate()->first()
                ?? (new NotificationPreference)->forceFill([
                    'user_id' => $recipient->user_id,
                    'event' => $event->value,
                    'in_app' => $event->defaultInApp(),
                    'sms' => $event->defaultSms(),
                    'push' => false,
                ]);
            $row->forceFill(['email' => false])->save();

            CampaignRecipient::query()->whereKey($recipient->id)->whereNull('unsubscribed_at')->update(['unsubscribed_at' => now()]);
        });
    }
}
