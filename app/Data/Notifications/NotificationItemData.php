<?php

declare(strict_types=1);

namespace App\Data\Notifications;

use App\Enums\NotificationEvent;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;

/**
 * One notification as the bell and /notifications show it (display data only). The stored url is
 * re-checked to be on this site — a notification can only ever link inside Oppam.
 */
final readonly class NotificationItemData
{
    public function __construct(
        public string $id,
        public string $title,
        public string $body,
        public string $url,
        public string $icon,
        public bool $unread,
        public string $when,
        public string $day,
        public ?string $actorPhoto = null,
        public string $iconClass = '',
    ) {}

    public static function fromModel(DatabaseNotification $notification): self
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;
        $event = NotificationEvent::tryFrom((string) $notification->type);
        $created = CarbonImmutable::parse($notification->created_at)->setTimezone((string) config('oppam.display_timezone'));
        $today = CarbonImmutable::now((string) config('oppam.display_timezone'))->startOfDay();

        return new self(
            id: (string) $notification->id,
            title: is_string($data['title'] ?? null) ? $data['title'] : __('Notification'),
            body: is_string($data['body'] ?? null) ? $data['body'] : '',
            url: self::internal($data['url'] ?? null),
            icon: $event?->icon() ?? 'fa-bell',
            unread: $notification->read_at === null,
            when: $created->diffForHumans(),
            day: match (true) {
                $created->gte($today) => 'today',
                $created->gte($today->subDay()) => 'yesterday',
                default => 'earlier',
            },
            // An <img src> (photos may come from the media host): http(s) only, never javascript:/data:.
            actorPhoto: is_string($data['actor_photo_thumb'] ?? null) && in_array(parse_url($data['actor_photo_thumb'], PHP_URL_SCHEME), ['http', 'https'], true)
                ? $data['actor_photo_thumb'] : null,
            iconClass: $event?->group()->iconClass() ?? '',
        );
    }

    /** Same-site links only (no open redirect, no javascript:); anything else goes to /notifications. */
    private static function internal(mixed $url): string
    {
        // A backslash is read as "/" by browsers ("/\evil.example" = "//evil.example"); control
        // characters are stripped by them. Neither belongs in a link we made.
        if (! is_string($url) || $url === '' || preg_match('/[\\\\\x00-\x20\x7f]/', $url) === 1) {
            return url('/notifications');
        }

        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $sameSite = $host === null
            ? $scheme === null && str_starts_with($url, '/') && ! str_starts_with($url, '//')
            : in_array($scheme, ['http', 'https'], true) && in_array($host, [parse_url((string) config('app.url'), PHP_URL_HOST), config('oppam.app_domain')], true);

        return $sameSite ? $url : url('/notifications');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
