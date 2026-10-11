<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A chat message (PRD §7.2, M07). Until chat arrives (P4.1) the only rows are the SYSTEM message
 * an accepted interest leaves in its new conversation. Only the server sets any column.
 *
 * @property string $id
 * @property string $conversation_id
 * @property string|null $sender_profile_id null for SYSTEM messages
 * @property string $client_id
 * @property MessageType $type
 * @property string|null $body
 */
final class Message extends Model
{
    use HasUlids;

    /** @var list<string> */
    protected $fillable = [];

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
