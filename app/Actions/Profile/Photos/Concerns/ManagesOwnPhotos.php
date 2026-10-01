<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos\Concerns;

use App\Domain\Profile\CompletenessCalculator;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Shared by the photo Actions (M11): authorize (ProfilePolicy::managePhotos), find a photo only
 * among THIS profile's photos (a foreign or unknown uuid is a 404, never someone else's
 * photo), and keep completeness current (R-M02-3 photo weight).
 */
trait ManagesOwnPhotos
{
    private function authorizePhotos(User $actor, Profile $profile): void
    {
        Gate::forUser($actor)->authorize('managePhotos', $profile);
    }

    private function ownPhoto(Profile $profile, string $uuid, string $collection = Profile::PHOTOS): Media
    {
        return Media::query()
            ->where('model_type', $profile->getMorphClass())
            ->where('model_id', $profile->getKey())
            ->where('collection_name', $collection)
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function refreshCompleteness(Profile $profile): void
    {
        $profile->unsetRelations();
        $profile->forceFill(['completeness' => app(CompletenessCalculator::class)->percent($profile)])->save();
    }
}
