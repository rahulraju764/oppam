<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ClientNoteType;
use Database\Factories\BrokerClientNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A bureau's note / call / meeting on a managed client, with an optional follow-up (B.6; P7.3). Nothing is mass-assignable.
 *
 * @property string $id
 * @property string $profile_id
 * @property string $broker_id
 * @property string $author_user_id
 * @property ClientNoteType $type
 * @property string|null $outcome
 * @property string $body
 * @property Carbon|null $follow_up_on
 * @property Carbon|null $follow_up_done_at
 * @property-read Broker|null $broker
 * @property-read Profile|null $profile
 */
final class BrokerClientNote extends Model
{
    /** @use HasFactory<BrokerClientNoteFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ClientNoteType::class,
            'follow_up_on' => 'date',
            'follow_up_done_at' => 'datetime',
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
