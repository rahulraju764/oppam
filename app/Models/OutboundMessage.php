<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommsChannel;
use App\Enums\OutboundStatus;
use Carbon\CarbonImmutable;
use Database\Factories\OutboundMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A14 delivery log row (P8.3a): one outbound email / SMS — masked recipient, event, provider id,
 * status. Never the message text. `payload` (encrypted) is a resendable member notification, kept
 * only for notifications that carry no link token or code (DeliveryRecorder). Written by
 * DeliveryRecorder, updated by the provider webhooks, pruned after 180 days.
 *
 * @property string $id
 * @property string|null $user_id
 * @property CommsChannel $channel
 * @property string $recipient_masked
 * @property string|null $event
 * @property string|null $notification_type
 * @property string|null $subject
 * @property OutboundStatus $status
 * @property string|null $provider_id
 * @property string|null $error_message
 * @property string|null $payload
 * @property string|null $resent_from_id
 * @property string|null $campaign_id
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $failed_at
 * @property CarbonImmutable|null $bounced_at
 * @property CarbonImmutable|null $resent_at
 * @property CarbonImmutable $created_at
 * @property-read User|null $user
 */
final class OutboundMessage extends Model
{
    /** @use HasFactory<OutboundMessageFactory> */
    use HasFactory, HasUlids;

    // Kept for the P8.3b campaign draft until P8.3b moves it to the enum.
    public const STATUS_SENT = 'SENT';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_BOUNCED = 'BOUNCED';

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['payload'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => CommsChannel::class,
            'status' => OutboundStatus::class,
            'payload' => 'encrypted',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'bounced_at' => 'immutable_datetime',
            'resent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function isResendable(): bool
    {
        return $this->status->isFailure() && $this->payload !== null && $this->user_id !== null && $this->resent_at === null;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
