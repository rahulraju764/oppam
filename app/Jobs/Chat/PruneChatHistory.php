<?php

declare(strict_types=1);

namespace App\Jobs\Chat;

use App\Enums\MessageType;
use App\Enums\SettingKey;
use App\Models\Conversation;
use App\Models\Media;
use App\Models\Message;
use App\Support\Facades\Settings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Chat retention (R-M07-6), nightly: a thread whose last activity (its last message, or its
 * creation when it has none) is older than `chat.retention_days` (730) is deleted with its
 * participants and messages (cascade), image files first — they are not covered by the cascade.
 * Batched; idempotent. The other half of R-M07-6 (both members deleted) is PurgeDeletedMember.
 */
final class PruneChatHistory implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    private const BATCH = 200;

    public int $timeout = 900;

    public function handle(): void
    {
        $cutoff = now()->subDays(Settings::int(SettingKey::ChatRetentionDays));

        do {
            $ids = Conversation::query()
                ->where(fn ($q) => $q->where('last_message_at', '<', $cutoff)
                    ->orWhere(fn ($none) => $none->whereNull('last_message_at')->where('created_at', '<', $cutoff)))
                ->limit(self::BATCH)->pluck('id')->all();

            if ($ids !== []) {
                self::deleteThreads($ids);
            }
        } while (count($ids) === self::BATCH);
    }

    /**
     * Delete threads with everything in them: image files (with their conversions), then the rows
     * (participants and messages go by cascade). Also used by PurgeDeletedMember.
     *
     * @param  list<string>  $ids
     */
    public static function deleteThreads(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $imageMessages = Message::query()->whereIn('conversation_id', $ids)->where('type', MessageType::Image->value)->pluck('id')->all();
        foreach (array_chunk($imageMessages, 500) as $chunk) {
            Media::query()->where('model_type', (new Message)->getMorphClass())->whereIn('model_id', $chunk)->get()
                ->each(fn (Media $media) => $media->delete());
        }

        Conversation::query()->whereKey($ids)->delete();
    }
}
