<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccessRequestStatus;
use App\Enums\AccessRequestType;
use Database\Factories\AccessRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "May I see your photos / horoscope?" (PRD M06). One row per type and pair; the owner approves
 * or declines. Written only by RequestAccess / RespondToAccessRequest.
 *
 * @property string $id
 * @property AccessRequestType $type
 * @property string $requester_profile_id
 * @property string $owner_profile_id
 * @property AccessRequestStatus $status
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class AccessRequest extends Model
{
    /** @use HasFactory<AccessRequestFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AccessRequestType::class,
            'status' => AccessRequestStatus::class,
            'responded_at' => 'datetime',
        ];
    }
}
