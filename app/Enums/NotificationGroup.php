<?php

declare(strict_types=1);

namespace App\Enums;

/** The /notifications filters (PRD M08 UI, template notifications.php) with their icons. */
enum NotificationGroup: string
{
    case Interests = 'interests';
    case Likes = 'likes';
    case Messages = 'messages';
    case Views = 'views';
    case Matches = 'matches';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Interests => __('Interests'),
            self::Likes => __('Likes'),
            self::Messages => __('Messages'),
            self::Views => __('Profile views'),
            self::Matches => __('Matches'),
            self::System => __('Account'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Interests => 'fa-heart',
            self::Likes => 'fa-thumbs-o-up',
            self::Messages => 'fa-envelope',
            self::Views => 'fa-eye',
            self::Matches => 'fa-bolt',
            self::System => 'fa-cog',
        };
    }

    /** The template's per-type icon accent (`.nt-icon-message` / `-view` / `-system`); '' = the default rose. */
    public function iconClass(): string
    {
        return match ($this) {
            self::Messages => 'nt-icon-message',
            self::Views => 'nt-icon-view',
            self::System => 'nt-icon-system',
            self::Interests, self::Likes, self::Matches => '',
        };
    }

    /** @return list<string> the notifications.type values in this group */
    public function events(): array
    {
        return array_values(array_map(
            fn (NotificationEvent $e): string => $e->value,
            array_filter(NotificationEvent::cases(), fn (NotificationEvent $e): bool => $e->group() === $this),
        ));
    }
}
