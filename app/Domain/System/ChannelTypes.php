<?php

declare(strict_types=1);

namespace App\Domain\System;

/**
 * A12 "Reverb per-channel-type counts": which of the app's channels (routes/channels.php, PRD §9.3,
 * §11A B.15) a Pusher-protocol channel name belongs to. Names carry ids, so only the TYPE leaves
 * the reader. Laravel adds `private-` / `presence-` to the names it subscribes to.
 */
final class ChannelTypes
{
    public const CHAT = 'chat';

    public const CHAT_PRESENCE = 'chat_presence';

    public const INBOX = 'inbox';

    public const ONLINE = 'online';

    public const MEMBER = 'member';

    public const BUREAU = 'bureau';

    public const ADMIN = 'admin';

    public const ANNOUNCEMENTS = 'announcements';

    public const OTHER = 'other';

    /** @var array<string, string> name prefix (after private- / presence-) => type, most specific first */
    private const PREFIXES = [
        'presence-chat.' => self::CHAT_PRESENCE,
        'chat.' => self::CHAT,
        'inbox.' => self::INBOX,
        'presence.online' => self::ONLINE,
        'App.Models.User.' => self::MEMBER,
        'broker-owner.' => self::BUREAU,
        'broker.' => self::BUREAU,
        'admin.' => self::ADMIN,
        'announcements' => self::ANNOUNCEMENTS,
    ];

    public static function of(string $channel): string
    {
        $name = (string) preg_replace('/^(private-encrypted-|private-|presence-)/', '', $channel);

        foreach (self::PREFIXES as $prefix => $type) {
            if (str_starts_with($name, $prefix)) {
                return $type;
            }
        }

        return self::OTHER;
    }

    /** @return array<string, string> type => label, in display order */
    public static function labels(): array
    {
        return [
            self::CHAT => __('Chat'),
            self::CHAT_PRESENCE => __('Chat presence'),
            self::INBOX => __('Inbox'),
            self::ONLINE => __('Online presence'),
            self::MEMBER => __('Member notifications'),
            self::BUREAU => __('Bureau'),
            self::ADMIN => __('Admin'),
            self::ANNOUNCEMENTS => __('Announcements'),
            self::OTHER => __('Other'),
        ];
    }
}
