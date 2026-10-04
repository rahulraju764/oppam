<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ConversationParticipantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of a conversation (PRD §7.2): read position, denormalised unread count, mute / archive.
 *
 * @property int $id
 * @property string $conversation_id
 * @property string $profile_id
 * @property string|null $last_read_message_id
 * @property int $unread_count
 * @property bool $is_muted
 * @property bool $is_archived
 * @property-read Conversation|null $conversation
 * @property-read Profile|null $profile
 */
final class ConversationParticipant extends Model
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unread_count' => 'integer', 'is_muted' => 'boolean', 'is_archived' => 'boolean'];
    }
}
