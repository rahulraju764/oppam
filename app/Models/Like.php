<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LikeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * "I like your profile" (PRD §7.2, M06) — one row per direction; a mutual like is both rows.
 * Written only by App\Actions\Engagement\ToggleLike.
 *
 * @property string $id
 * @property string $from_profile_id
 * @property string $to_profile_id
 * @property \Illuminate\Support\Carbon|null $seen_at the recipient saw it in "Liked me"
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class Like extends Model
{
    /** @use HasFactory<LikeFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['seen_at' => 'datetime'];
    }
}
