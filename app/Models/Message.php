<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MessageType;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as SpatieMedia;

/**
 * A chat message (PRD §7.2, M07). TEXT and IMAGE messages are written by SendMessage; the SYSTEM
 * message "You are now connected" by AcceptInterest. The ULID id sorts by time: it is the thread's
 * order and cursor. `client_id` makes a send idempotent (unique per conversation). An unsent
 * message keeps its row as a tombstone (`deleted_for_all_at`, no body, no image); so does one a
 * moderator deleted (`removed_by_admin_at`, A13: "This message was removed by Oppam"). `body_hash`
 * is the normalised text's SHA-256 for the repeated-message check (ContentSafety). An IMAGE
 * message's file is on the PRIVATE disk, its preview too — served only by ChatImageController to
 * the two participants. Only the server sets any column.
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
 * @property \Illuminate\Support\Carbon|null $removed_by_admin_at
 * @property string|null $body_hash
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read Conversation|null $conversation
 */
final class Message extends Model implements HasMedia
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids, InteractsWithMedia;

    public const IMAGE = 'image';

    /** @var list<string> */
    protected $fillable = [];

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return HasMany<MessageFlag, $this> */
    public function flags(): HasMany
    {
        return $this->hasMany(MessageFlag::class);
    }

    /** Unsent by its sender or removed by a moderator: a tombstone without text or image. */
    public function isGone(): bool
    {
        return $this->deleted_for_all_at !== null || $this->removed_by_admin_at !== null;
    }

    public function registerMediaCollections(): void
    {
        $private = (string) config('oppam.media.private_disk');

        $this->addMediaCollection(self::IMAGE)
            ->singleFile()
            ->useDisk($private)
            ->storeConversionsOnDisk($private)
            ->acceptsMimeTypes(['image/jpeg']);   // ImageSanitizer re-encodes every upload to JPEG
    }

    /** The bubble's preview: WebP, at most 800 px, on the private disk (queued on `media`). */
    public function registerMediaConversions(?SpatieMedia $media = null): void
    {
        $this->addMediaConversion('preview')->performOnCollections(self::IMAGE)->nonOptimized()
            ->fit(Fit::Max, 800, 800)->format('webp');
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
            'removed_by_admin_at' => 'datetime',
        ];
    }
}
