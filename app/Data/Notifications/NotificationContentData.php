<?php

declare(strict_types=1);

namespace App\Data\Notifications;

/**
 * What a member notification says (PRD M08 `toArray()` shape): stored in `notifications.data` and
 * sent as the live payload. Codes and display text only — never a ULID, phone, email or a private
 * photo (CLAUDE.md security rule 6); `actor_photo_thumb` is only ever a URL the recipient may see.
 * `url` is always an internal route.
 */
final readonly class NotificationContentData
{
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public ?string $actorCode = null,
        public ?string $actorName = null,
        public ?string $actorPhotoThumb = null,
    ) {}

    /** @return array{type: string, actor_code: ?string, actor_name: ?string, actor_photo_thumb: ?string, title: string, body: string, url: string, icon: string} */
    public function toArray(string $type, string $icon): array
    {
        return [
            'type' => $type,
            'actor_code' => $this->actorCode,
            'actor_name' => $this->actorName,
            'actor_photo_thumb' => $this->actorPhotoThumb,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'icon' => $icon,
        ];
    }
}
