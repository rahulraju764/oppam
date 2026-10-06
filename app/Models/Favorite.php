<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\FavoriteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A private bookmark (PRD §7.2, M06): never visible to the favourited member, never notified
 * (R-M06-6). Written only by App\Actions\Engagement\ToggleFavorite / SaveFavoriteNote.
 *
 * @property string $id
 * @property string $profile_id the owner
 * @property string $favorite_profile_id
 * @property string|null $note private note, plain text
 * @property \Illuminate\Support\Carbon|null $created_at
 */
final class Favorite extends Model
{
    /** @use HasFactory<FavoriteFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];
}
