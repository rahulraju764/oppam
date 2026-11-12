<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The A14 automated journeys (PRD §11 A14, P8.3b2). Who qualifies is code (one audience query per
 * journey); whether it runs, on which days and on which channel is configured on the Journeys
 * screen (`automated_journeys`). Plan-expiry reminders are not a journey here: they stay the P5.2
 * job (M10: 7 d / 1 d, decisions 2026-11-08 (2)) and are shown on the screen as built-in.
 */
enum JourneyKey: string
{
    case PendingInterest = 'pending_interest';
    case WizardIncomplete = 'wizard_incomplete';
    case InactiveMatches = 'inactive_matches';
    case WinBack = 'win_back';

    /** The member notification event (preferences, templates, the /settings matrix). */
    public function event(): NotificationEvent
    {
        return match ($this) {
            self::PendingInterest => NotificationEvent::JourneyInterestReminder,
            self::WizardIncomplete => NotificationEvent::JourneyWizardReminder,
            self::InactiveMatches => NotificationEvent::JourneyNewMatches,
            self::WinBack => NotificationEvent::JourneyWinBack,
        };
    }

    /**
     * One journey message per member per day: when a member qualifies for several, the first in this
     * order wins (an interest waiting for an answer matters most, an offer least).
     *
     * @return list<self>
     */
    public static function byPriority(): array
    {
        return [self::PendingInterest, self::WizardIncomplete, self::InactiveMatches, self::WinBack];
    }

    /** @return list<int> the PRD's day offsets (the seeded configuration) */
    public function defaultOffsets(): array
    {
        return match ($this) {
            self::PendingInterest => [3],
            self::WizardIncomplete => [1, 3, 7],
            self::InactiveMatches => [14],
            self::WinBack => [30],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PendingInterest => __('Unanswered interest reminder'),
            self::WizardIncomplete => __('Finish your profile reminders'),
            self::InactiveMatches => __('New matches for inactive members'),
            self::WinBack => __('Win-back after a plan ended'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::PendingInterest => __('To a member who has not answered an interest, N days after it was sent.'),
            self::WizardIncomplete => __('To a member whose profile is still a draft, N days after registering.'),
            self::InactiveMatches => __('To a member not seen for N days who has new daily matches waiting.'),
            self::WinBack => __('To a member whose paid plan ended N days ago and who has not renewed.'),
        };
    }

    /** Each offset counts days since … */
    public function offsetMeaning(): string
    {
        return match ($this) {
            self::PendingInterest => __('days after the interest was sent'),
            self::WizardIncomplete => __('days after registering'),
            self::InactiveMatches => __('days since last active'),
            self::WinBack => __('days after the plan ended'),
        };
    }
}
