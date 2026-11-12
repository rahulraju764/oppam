<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\JourneyKey;
use App\Enums\ProfileStatus;
use Carbon\CarbonImmutable;

/**
 * A14 "incomplete wizard reminders (1 d, 3 d, 7 d)": a member whose profile is still a DRAFT
 * `offset` days after it was created (registration). One reminder per step per profile.
 */
final class WizardIncompleteAudience implements JourneyAudience
{
    public function key(): JourneyKey
    {
        return JourneyKey::WizardIncomplete;
    }

    public function candidates(int $offset, CarbonImmutable $now): iterable
    {
        $window = JourneyWindow::for($offset, $now);

        $rows = ReachableMembers::query()
            ->where('profiles.status', ProfileStatus::Draft->value)
            ->where('profiles.created_at', '>=', $window->from)
            ->where('profiles.created_at', '<', $window->until)
            ->select(['profiles.id as profile_id', 'users.id as user_id'])
            ->lazyById(500, 'profiles.id', 'profile_id');

        foreach ($rows as $row) {
            yield new JourneyCandidate(userId: (string) $row->user_id, subjectRef: (string) $row->profile_id);
        }
    }
}
