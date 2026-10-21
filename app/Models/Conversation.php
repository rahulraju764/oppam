<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ConversationStatus;
use App\Enums\FreezeReason;
use Database\Factories\ConversationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A chat thread between two profiles (PRD §7.2, M07) — one per pair (`pair_key`). Created when an
 * interest is accepted (P3.4); written to by SendMessage (P4.1). Only the server sets any column.
 *
 * @property string $id
 * @property string|null $interest_id
 * @property string $pair_key
 * @property string|null $last_message_id
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property ConversationStatus $status
 * @property \Illuminate\Support\Carbon|null $frozen_at
 * @property FreezeReason|null $frozen_reason
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

    /** "OPM1 · OPM2": the members of this thread by profile code (admin audit labels, A13 screens). */
    public function memberCodesLabel(): string
    {
        return Profile::withTrashed()
            ->whereIn('id', ConversationParticipant::query()->select('profile_id')->where('conversation_id', $this->id))
            ->orderBy('code')->pluck('code')->implode(' · ');
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'last_message_at' => 'datetime',
            'frozen_at' => 'datetime',
            'frozen_reason' => FreezeReason::class,
        ];
    }
}
