<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Targeted message campaign across In-App, Email, or SMS (A14).
 *
 * @property string $id
 * @property string $name
 * @property string $channel email, sms, in_app
 * @property array<string, mixed> $segment_filters
 * @property string|null $template_id
 * @property string|null $subject
 * @property string $message
 * @property string $status DRAFT, PENDING_APPROVAL, APPROVED, SCHEDULED, SENDING, COMPLETED, CANCELLED
 * @property CarbonImmutable|null $scheduled_at
 * @property int $throttle_per_minute
 * @property int $recipient_count
 * @property int $sent_count
 * @property int $delivered_count
 * @property int $failed_count
 * @property string $created_by
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AdminUser $creator
 * @property-read AdminUser|null $approver
 * @property-read CommunicationTemplate|null $template
 */
final class Campaign extends Model
{
    use HasUlids;

    public const STATUS_DRAFT = 'DRAFT';

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_SCHEDULED = 'SCHEDULED';

    public const STATUS_SENDING = 'SENDING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $table = 'campaigns';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'segment_filters' => 'array',
            'throttle_per_minute' => 'integer',
            'recipient_count' => 'integer',
            'sent_count' => 'integer',
            'delivered_count' => 'integer',
            'failed_count' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function needsSecondApproval(): bool
    {
        return $this->recipient_count > 10_000;
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'created_by');
    }

    /** @return BelongsTo<AdminUser, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'approved_by');
    }

    /** @return BelongsTo<CommunicationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CommunicationTemplate::class, 'template_id');
    }

    /** @return HasMany<OutboundMessage, $this> */
    public function outboundMessages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class);
    }
}
