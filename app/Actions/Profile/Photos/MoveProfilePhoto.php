<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Move one photo to a new position (M11): drag-to-reorder, "Move earlier / later" and "Make
 * primary" (position 0 — the first approved photo is the one others see first). Positions are
 * clamped to the list, so any number from the browser is safe.
 */
final class MoveProfilePhoto
{
    use ManagesOwnPhotos;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     */
    public function handle(User $actor, Profile $profile, string $uuid, int $position): void
    {
        $this->authorizePhotos($actor, $profile);
        $moving = $this->ownPhoto($profile, $uuid);

        DB::transaction(function () use ($profile, $moving, $position): void {
            $ids = Media::query()
                ->where('model_type', $profile->getMorphClass())
                ->where('model_id', $profile->getKey())
                ->where('collection_name', Profile::PHOTOS)
                ->orderBy('order_column')
                ->lockForUpdate()
                ->pluck('id')
                ->reject(fn (string $id): bool => $id === $moving->id)
                ->values()
                ->all();

            array_splice($ids, max(0, min($position, count($ids))), 0, [$moving->id]);

            Media::setNewOrder($ids);
        });
    }
}
