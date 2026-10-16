<?php

declare(strict_types=1);

namespace App\Data\Chat;

use Carbon\CarbonImmutable;

/**
 * A thread's messages grouped for display (PRD M07 "day separators, same-sender grouping"): by IST
 * day ("Today", "Yesterday", "12 Oct 2026"); `grouped` = same sender as the message before within
 * 5 minutes (the bubbles sit closer). Presentation only.
 */
final class ThreadDays
{
    public const GROUP_MINUTES = 5;

    /**
     * @param  list<MessageData>  $messages
     * @return list<array{label: string, messages: list<array{message: MessageData, grouped: bool}>}>
     */
    public static function of(array $messages, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now((string) config('oppam.display_timezone'));
        $days = [];
        $previous = null;

        foreach ($messages as $message) {
            $key = $message->at->toDateString();
            if (! isset($days[$key])) {
                $days[$key] = ['label' => match (true) {
                    $message->at->isSameDay($now) => __('Today'),
                    $message->at->isSameDay($now->subDay()) => __('Yesterday'),
                    default => $message->at->translatedFormat('j M Y'),
                }, 'messages' => []];
                $previous = null;
            }

            $grouped = $previous !== null && ! $message->system && ! $previous->system && $previous->mine === $message->mine
                && $message->at->diffInMinutes($previous->at, true) < self::GROUP_MINUTES;
            $days[$key]['messages'][] = ['message' => $message, 'grouped' => $grouped];
            $previous = $message;
        }

        return array_values($days);
    }
}
