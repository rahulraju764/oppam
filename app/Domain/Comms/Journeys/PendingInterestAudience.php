<?php

declare(strict_types=1);

namespace App\Domain\Comms\Journeys;

use App\Enums\InterestStatus;
use App\Enums\JourneyKey;
use App\Enums\ProfileStatus;
use Carbon\CarbonImmutable;

/**
 * A14 "pending-interest reminder (3 d)": the receiver of an interest still PENDING (not expired) that
 * was sent `offset` days ago, while both profiles are ACTIVE. A re-sent interest reuses its row with
 * a new `sent_at`, so the subject is the interest + that send time. The block check (either
 * direction) is done per member in RunJourneys and again by the notification (R-M08-1).
 */
final class PendingInterestAudience implements JourneyAudience
{
    public function key(): JourneyKey
    {
        return JourneyKey::PendingInterest;
    }

    public function candidates(int $offset, CarbonImmutable $now): iterable
    {
        $window = JourneyWindow::for($offset, $now);

        $rows = ReachableMembers::query()
            ->join('interests', 'interests.to_profile_id', '=', 'profiles.id')
            ->join('profiles as sender', 'sender.id', '=', 'interests.from_profile_id')
            ->where('profiles.status', ProfileStatus::Active->value)
            ->where('sender.status', ProfileStatus::Active->value)
            ->whereNull('sender.deleted_at')
            ->where('interests.status', InterestStatus::Pending->value)
            ->where('interests.expires_at', '>', $now->utc())
            ->where('interests.sent_at', '>=', $window->from)
            ->where('interests.sent_at', '<', $window->until)
            ->select(['interests.id as interest_id', 'interests.sent_at', 'interests.from_profile_id', 'users.id as user_id'])
            ->lazyById(500, 'interests.id', 'interest_id');

        foreach ($rows as $row) {
            yield new JourneyCandidate(
                userId: (string) $row->user_id,
                subjectRef: $row->interest_id.':'.CarbonImmutable::parse((string) $row->sent_at)->timestamp,
                actorProfileId: (string) $row->from_profile_id,
            );
        }
    }
}
