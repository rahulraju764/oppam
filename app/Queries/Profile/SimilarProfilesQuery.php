<?php

declare(strict_types=1);

namespace App\Queries\Profile;

use App\Domain\Safety\BlockList;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Similar Profiles" on a profile page (M03): other ACTIVE profiles of the same gender as the one
 * being viewed, same religion, age within ±3 years — never the viewer, the viewed profile or
 * anyone in a blocked pair with the viewer. Most complete first, then most recently published.
 */
final class SimilarProfilesQuery
{
    public function __construct(private readonly BlockList $blocks) {}

    /** @return Collection<int, Profile> */
    public function for(Profile $target, Profile $viewer, int $limit = 6): Collection
    {
        $query = Profile::query()
            ->where('status', ProfileStatus::Active)
            ->where('gender', $target->gender)
            ->whereNotIn('id', [$target->id, $viewer->id, ...$this->blocks->hiddenFrom($viewer)])
            ->with(['educationCareer', 'privacySetting'])
            ->orderByDesc('completeness')
            ->orderByDesc('published_at')
            ->limit($limit);

        if ($target->religion_id !== null) {
            $query->where('religion_id', $target->religion_id);
        }

        if ($target->dob !== null) {
            $query->whereBetween('dob', [$target->dob->subYears(3)->toDateString(), $target->dob->addYears(3)->toDateString()]);
        }

        return $query->get();
    }
}
