<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chat message (PRD §7.2, M07). TEXT messages are written by SendMessage; the SYSTEM message
 * "You are now connected" by AcceptInterest. The ULID id sorts by time: it is the thread's order
 * and cursor. `client_id` makes a send idempotent (unique per conversation). Only the server sets
 * any column.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string|null $sender_profile_id null for SYSTEM messages
 * @property string $client_id
 * @property MessageType $type
 * @property string|null $body
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $read_at
 * @property bool $is_flagged
 * @property \Illuminate\Support\Carbon|null $deleted_for_all_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Conversation|null $conversation
 */
final class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'is_flagged' => 'boolean',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'deleted_for_all_at' => 'datetime',
        ];
    }
}
