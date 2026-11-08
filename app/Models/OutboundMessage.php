<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log of every outbound transactional or marketing email/SMS/in-app notification (A14).
 *
 * @property string $id
 * @property string|null $user_id
 * @property string $channel email, sms, in_app
 * @property string $recipient phone, email, or user ULID
 * @property string|null $event
 * @property string|null $subject
 * @property string|null $content
 * @property string $status SENT, DELIVERED, FAILED, BOUNCED
 * @property string|null $provider_id
 * @property string|null $error_message
 * @property string|null $campaign_id
 * @property CarbonImmutable $sent_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 * @property-read Campaign|null $campaign
 */
final class OutboundMessage extends Model
{
    use HasUlids;

    public const STATUS_SENT = 'SENT';

    public const STATUS_DELIVERED = 'DELIVERED';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_BOUNCED = 'BOUNCED';

    protected $table = 'outbound_messages';

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sent_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
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
