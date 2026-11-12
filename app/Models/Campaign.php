<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Comms\CampaignSegment;
use App\Enums\CampaignStatus;
use App\Enums\CommsChannel;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An A14 campaign (P8.3b1): one message on one channel (in-app, email or SMS) to a segment of
 * members. Addressed by `code` (CMP…) everywhere outside the database. Its audience is frozen in
 * campaign_recipients when it is submitted; its numbers are counted from those rows and the
 * delivery log (CampaignStats), never stored as counters.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property CommsChannel $channel
 * @property array<string, mixed> $segment_filters
 * @property-read CampaignSegment $segment
 * @property string|null $template_id
 * @property string|null $subject email subject / in-app title
 * @property string $message email Markdown / in-app text / SMS registered text
 * @property string|null $action_url
 * @property string|null $action_text
 * @property string|null $dlt_template_id
 * @property CampaignStatus $status
 * @property CarbonImmutable|null $scheduled_at
 * @property int $throttle_per_minute
 * @property int $recipient_count
 * @property string $created_by
 * @property string|null $submitted_by
 * @property CarbonImmutable|null $submitted_at
 * @property string|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $started_at
 * @property string|null $batch_token the one batch chain allowed to run (SendCampaignBatch)
 * @property CarbonImmutable|null $next_batch_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AdminUser|null $creator
 * @property-read AdminUser|null $approver
 */
final class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory, HasUlids;

    protected $table = 'campaigns';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => CommsChannel::class,
            'status' => CampaignStatus::class,
            'segment_filters' => 'array',
            'throttle_per_minute' => 'integer',
            'recipient_count' => 'integer',
            'scheduled_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'next_batch_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    /** @return Attribute<CampaignSegment, never> */
    protected function segment(): Attribute
    {
        return Attribute::get(fn (): CampaignSegment => CampaignSegment::fromArray($this->segment_filters));
    }

    public function isEditable(): bool
    {
        return $this->status === CampaignStatus::Draft;
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

    /** @return HasMany<CampaignRecipient, $this> */
    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    /** @return HasMany<OutboundMessage, $this> */
    public function outboundMessages(): HasMany
    {
        return $this->hasMany(OutboundMessage::class);
    }
}
