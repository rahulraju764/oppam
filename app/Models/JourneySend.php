<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JourneyKey;
use App\Enums\JourneySendStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The A14 journey ledger (P8.3b2): one row per member × journey step × subject, written by RunJourneys
 * before the message is queued, so a step never goes out twice. Deleted with the member (purge).
 *
 * @property int $id
 * @property JourneyKey $journey_key
 * @property string $user_id
 * @property int $step
 * @property string $subject_ref
 * @property CarbonImmutable $sent_on
 * @property JourneySendStatus $status
 * @property CarbonImmutable $created_at
 */
final class JourneySend extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'journey_key' => JourneyKey::class,
            'status' => JourneySendStatus::class,
            'step' => 'integer',
            'sent_on' => 'immutable_date',
            'created_at' => 'immutable_datetime',
        ];
    }
}
