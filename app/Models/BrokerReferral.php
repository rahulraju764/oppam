<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReferralSource;
use App\Enums\ReferralStatus;
use Database\Factories\BrokerReferralFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of a bureau's commission ledger — referral or managed-profile conversion (B.5 / B.10; P7.2). Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $broker_id
 * @property string $profile_id
 * @property ReferralSource $source
 * @property string|null $order_id
 * @property int $commission_paise
 * @property ReferralStatus $status
 * @property string|null $payout_item_id
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 */
final class BrokerReferral extends Model
{
    /** @use HasFactory<BrokerReferralFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => ReferralSource::class,
            'status' => ReferralStatus::class,
            'commission_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<Broker, $this> */
    public function broker(): BelongsTo
    {
        return $this->belongsTo(Broker::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }
}
