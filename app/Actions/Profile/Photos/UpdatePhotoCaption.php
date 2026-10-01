<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Events\Admin\ModerationQueueChanged;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Change a photo's caption (M11, ≤ 100 characters). Captions are public text, so a new caption
 * on an already APPROVED photo goes back to A04 as a PHOTO item carrying the caption (the photo
 * stays visible meanwhile); on a pending photo it is reviewed with the photo itself.
 */
final class UpdatePhotoCaption
{
    use ManagesOwnPhotos;

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, string $uuid, ?string $caption): void
    {
        $this->authorizePhotos($actor, $profile);
        $photo = $this->ownPhoto($profile, $uuid);

        Validator::make(['caption' => $caption], ['caption' => ['nullable', 'string', 'max:100']], [], ['caption' => __('caption')])->validate();

        $caption = $caption !== null && trim($caption) !== '' ? trim($caption) : null;

        if ($caption === $photo->caption) {
            return;
        }

        $requeued = DB::transaction(function () use ($profile, $photo, $caption): bool {
            $photo->forceFill(['caption' => $caption])->save();

            if ($photo->moderation_status !== PhotoStatus::Approved || $caption === null) {
                return false;
            }

            $item = new ModerationItem;
            $item->forceFill([
                'type' => ModerationItemType::Photo,
                'profile_id' => $profile->id,
                'subject_id' => $photo->uuid,
                'fields' => ['caption' => $caption],
                'status' => ModerationStatus::Open,
                'is_priority' => $profile->is_premium,
                'submitted_at' => now(),
            ])->save();

            return true;
        });

        if ($requeued) {
            ModerationQueueChanged::dispatch(ModerationItemType::Photo);
        }
    }
}
