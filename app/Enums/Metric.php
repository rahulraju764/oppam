<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The KPIs kept in `metric_snapshots` for the admin dashboard (PRD A02), each with its label and
 * the definition shown in its tooltip. A "stock" is a level on that day (total members); a "flow"
 * is what happened during the day (new members). Revenue, payments and the other modules' metrics
 * are added by their sessions (owner decision 2026-10-11: only real data is shown).
 */
enum Metric: string
{
    case TotalMembers = 'members_total';
    case NewMembers = 'members_new';
    case ActiveMembers7d = 'members_active_7d';
    case PaidMembers = 'members_paid';
    case Activations = 'profiles_activated';
    case ActiveMale = 'profiles_active_male';
    case ActiveFemale = 'profiles_active_female';
    case Likes = 'likes';
    case InterestsSent = 'interests_sent';
    case InterestsAccepted = 'interests_accepted';
    case Messages = 'messages';

    public function label(): string
    {
        return match ($this) {
            self::TotalMembers => __('Total members'),
            self::NewMembers => __('New today'),
            self::ActiveMembers7d => __('Active (7 days)'),
            self::PaidMembers => __('Paid members'),
            self::Activations => __('Profiles approved'),
            self::ActiveMale => __('Active grooms'),
            self::ActiveFemale => __('Active brides'),
            self::Likes => __('Likes today'),
            self::InterestsSent => __('Interests sent'),
            self::InterestsAccepted => __('Interests accepted'),
            self::Messages => __('Messages today'),
        };
    }

    /** What the number means — the tile's tooltip (PRD A02 "tooltip definition"). */
    public function definition(): string
    {
        return match ($this) {
            self::TotalMembers => __('Member accounts that are not deleted.'),
            self::NewMembers => __('Member accounts registered during the day (IST), verified or not; accounts deleted since are not counted.'),
            self::ActiveMembers7d => __('Members seen on the site in the last 7 days.'),
            self::PaidMembers => __('Members with a running paid or complimentary plan.'),
            self::Activations => __('Profiles approved and published during the day (IST).'),
            self::ActiveMale => __('ACTIVE male profiles.'),
            self::ActiveFemale => __('ACTIVE female profiles.'),
            self::Likes => __('Likes given during the day (IST) that still stand.'),
            self::InterestsSent => __('Interests sent during the day (IST), re-sends included.'),
            self::InterestsAccepted => __('Interests accepted during the day (IST).'),
            self::Messages => __('Chat messages sent during the day (IST), system messages excluded.'),
        };
    }

    /** A level on the day (true) or a count of what happened during it (false). */
    public function isStock(): bool
    {
        return in_array($this, [self::TotalMembers, self::ActiveMembers7d, self::PaidMembers, self::ActiveMale, self::ActiveFemale], true);
    }
}
