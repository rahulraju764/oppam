<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterestStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One step in an interest's history (PRD §7.1 `interest_events`): the status it moved to and who
 * moved it (null = the system, e.g. expiry). Append-only.
 *
 * @property string $id
 * @property string $interest_id
 * @property InterestStatus $status
 * @property string|null $actor_profile_id
 */
final class InterestEvent extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => InterestStatus::class];
    }

    public static function record(Interest $interest, InterestStatus $status, ?string $actorProfileId): void
    {
        $event = new self;
        $event->interest_id = $interest->id;
        $event->status = $status;
        $event->actor_profile_id = $actorProfileId;
        $event->save();
    }
}
