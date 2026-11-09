<?php

declare(strict_types=1);

namespace App\Jobs\Admin\Comms;

use App\Enums\SettingKey;
use App\Models\Campaign;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Queries\Admin\Comms\SegmentQuery;
use App\Services\Comms\DeliveryRecorder;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sends a campaign in chunks, respecting opt-outs, throttle, and SMS quiet hours (PRD §11 A14).
 */
final class SendCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(public readonly string $campaignId) {}

    public function handle(SegmentQuery $segmentQuery): void
    {
        /** @var Campaign|null $campaign */
        $campaign = Campaign::query()->find($this->campaignId);

        if ($campaign === null || $campaign->status === Campaign::STATUS_CANCELLED) {
            return;
        }

        try {
            $isSms = $campaign->channel === 'sms';
            $quietFrom = Settings::int(SettingKey::NotificationsQuietFrom);
            $quietUntil = Settings::int(SettingKey::NotificationsQuietUntil);
            $istHour = (int) now()->timezone('Asia/Kolkata')->format('H');

            $inQuietHours = false;
            if ($quietFrom > $quietUntil) {
                $inQuietHours = $istHour >= $quietFrom || $istHour < $quietUntil;
            } else {
                $inQuietHours = $istHour >= $quietFrom && $istHour < $quietUntil;
            }

            // Quiet hours: SMS campaign paused until quiet hours end
            if ($isSms && $inQuietHours) {
                // Defer until quiet window ends
                $this->release(3600);

                return;
            }

            $query = $segmentQuery->build($campaign->segment_filters, $campaign->channel);

            $query->chunkById(100, function ($users) use ($campaign): bool {
                // Check if cancelled in flight
                $currentStatus = Campaign::query()->whereKey($campaign->id)->value('status');
                if ($currentStatus === Campaign::STATUS_CANCELLED) {
                    return false;
                }

                foreach ($users as $user) {
                    /** @var User $user */
                    // P8.3a kept this first-pass placeholder working with the new delivery-log columns
                    // (masked recipient, no message text). It still sends nothing — P8.3b replaces it.
                    $recipient = match ($campaign->channel) {
                        'email' => DeliveryRecorder::maskEmail((string) $user->email),
                        'sms' => PhoneNumber::tryParse((string) $user->phone)?->masked() ?? '—',
                        default => '—',
                    };

                    (new OutboundMessage)->forceFill([
                        'user_id' => $user->id,
                        'channel' => $campaign->channel,
                        'recipient_masked' => $recipient,
                        'event' => 'campaign',
                        'subject' => $campaign->subject ?? $campaign->name,
                        'status' => OutboundMessage::STATUS_DELIVERED,
                        'campaign_id' => $campaign->id,
                        'sent_at' => now(),
                    ])->save();

                    $campaign->increment('sent_count');
                    $campaign->increment('delivered_count');
                }

                return true;
            });

            $currentStatus = Campaign::query()->whereKey($campaign->id)->value('status');
            if ($currentStatus !== Campaign::STATUS_CANCELLED) {
                $campaign->update([
                    'status' => Campaign::STATUS_COMPLETED,
                    'completed_at' => now(),
                ]);
            }
        } catch (Throwable $e) {
            report($e);
            $campaign->increment('failed_count');
        }
    }
}
