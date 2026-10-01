<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Enums\ModerationItemType;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Delete one of the member's own photos (M11): the files (original + conversions) go, and a
 * review still waiting for it leaves the A04 queue. The member's other photos close the gap.
 */
final class DeleteProfilePhoto
{
    use ManagesOwnPhotos;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException a uuid that isn't one of this profile's photos
     */
    public function handle(User $actor, Profile $profile, string $uuid): void
    {
        $this->authorizePhotos($actor, $profile);
        $photo = $this->ownPhoto($profile, $uuid);

        // The row and its files go first (outside any transaction, so a rollback can't leave a row
        // whose files are already gone); then the queue item and completeness follow it.
        $photo->delete();

        DB::transaction(function () use ($profile, $photo): void {
            ModerationItem::query()
                ->where('profile_id', $profile->id)
                ->ofType(ModerationItemType::Photo)
                ->where('subject_id', $photo->uuid)
                ->pending()
                ->delete();

            $this->refreshCompleteness($profile);
        });

        ModerationQueueChanged::dispatch(ModerationItemType::Photo);
    }
}
