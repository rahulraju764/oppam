<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationStatus;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat thread between two profiles (PRD §7.2, M07) — one per pair (`pair_key`). Created when an
 * interest is accepted (P3.4); messages arrive in P4.1. Only the server sets any column.
 *
 * @property string $id
 * @property string|null $interest_id
 * @property string $pair_key
 * @property string|null $last_message_id
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property ConversationStatus $status
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ConversationParticipant> $participants
 */
final class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** The pair key for two profiles: their ids sorted, so A|B and B|A are the same thread. */
    public static function pairKey(string $a, string $b): string
    {
        $ids = [$a, $b];
        sort($ids, SORT_STRING);

        return implode('|', $ids);
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
        ];
    }
}
