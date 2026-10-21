<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Kinds of engagement abuse signal (PRD A13 "Engagement abuse monitors"). Raised as it happens (like
 * and favourite spam by their guards, repeated messages by ContentSafety, one-sided chat by
 * SendMessage) or by the hourly DetectEngagementSignals job (interest spam, new accounts messaging
 * many members).
 */
enum EngagementSignalType: string
{
    case LikeSpam = 'LIKE_SPAM';
    case FavoriteSpam = 'FAVORITE_SPAM';
    case InterestSpam = 'INTEREST_SPAM';
    case MessageRepetition = 'MESSAGE_REPETITION';
    case OneSidedChat = 'ONE_SIDED_CHAT';
    case NewAccountMassMessaging = 'NEW_ACCOUNT_MESSAGING';

    public function label(): string
    {
        return match ($this) {
            self::LikeSpam => __('Like spam'),
            self::FavoriteSpam => __('Mass favourites (automation)'),
            self::InterestSpam => __('Interest spam (almost none accepted)'),
            self::MessageRepetition => __('Same message to many members'),
            self::OneSidedChat => __('One-sided messaging'),
            self::NewAccountMassMessaging => __('New account messaging many members'),
        };
    }

    /**
     * What the counts in a signal's details say, for the Signals list (numbers only, never text).
     *
     * @param  array<string, int|string>|null  $details
     */
    public function describe(?array $details): string
    {
        $n = fn (string $key): int => (int) ($details[$key] ?? 0);

        return match ($this) {
            self::LikeSpam => __('More than :likes likes in :minutes minutes', ['likes' => $n('likes'), 'minutes' => $n('window_minutes')]),
            self::FavoriteSpam => __('Kept adding / removing favourites faster than :max a minute', ['max' => $n('changes_per_minute')]),
            self::InterestSpam => __(':sent interests in :days days, :accepted accepted', ['sent' => $n('sent'), 'days' => $n('window_days'), 'accepted' => $n('accepted')]),
            self::MessageRepetition => __('The same message into :threads conversations within :minutes minutes', ['threads' => $n('threads'), 'minutes' => $n('window_minutes')]),
            self::OneSidedChat => __(':count messages in a row without a reply', ['count' => $n('unanswered')]),
            self::NewAccountMassMessaging => __('New account writing in :threads conversations in 24 hours', ['threads' => $n('threads_24h')]),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
