<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommsChannel;
use App\Enums\JourneyKey;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One A14 automated journey's configuration (P8.3b2): on / off, the day offsets it sends on and its
 * one channel. Rows are seeded by the migration; admins change them through SaveJourney only.
 *
 * @property string $id
 * @property JourneyKey $key
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property list<int> $offsets
 * @property CommsChannel $channel
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AutomatedJourney extends Model
{
    use HasUlids;

    protected $table = 'automated_journeys';

    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'key' => JourneyKey::class,
            'channel' => CommsChannel::class,
            'is_active' => 'boolean',
            'offsets' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
