<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InterestStatus;
use Database\Factories\InterestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A formal proposal from one profile to another (PRD §7.2, M06) — one row per direction; a re-send
 * reuses it. Written only by the interest Actions (app/Actions/Engagement).
 *
 * @property string $id
 * @property string $from_profile_id
 * @property string $to_profile_id
 * @property InterestStatus $status
 * @property string|null $message
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon|null $responded_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
final class Interest extends Model
{
    /** @use HasFactory<InterestFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InterestStatus::class,
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
