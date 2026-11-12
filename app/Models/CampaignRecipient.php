<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CampaignRecipientStatus;
use App\Enums\CampaignSkipReason;
use Carbon\CarbonImmutable;
use Database\Factories\CampaignRecipientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One member in a campaign's frozen audience (P8.3b1). Its numeric id appears only inside signed
 * click / unsubscribe links (CampaignLinks), never on its own. Deleted with the member (purge).
 *
 * @property int $id
 * @property string $campaign_id
 * @property string $user_id
 * @property CampaignRecipientStatus $status
 * @property CampaignSkipReason|null $skip_reason
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $clicked_at
 * @property CarbonImmutable|null $unsubscribed_at
 * @property CarbonImmutable $created_at
 * @property-read Campaign $campaign
 * @property-read User|null $user null only if the user row is gone (soft-deleted users are loaded withTrashed)
 */
final class CampaignRecipient extends Model
{
    /** @use HasFactory<CampaignRecipientFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CampaignRecipientStatus::class,
            'skip_reason' => CampaignSkipReason::class,
            'sent_at' => 'immutable_datetime',
            'clicked_at' => 'immutable_datetime',
            'unsubscribed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Campaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
