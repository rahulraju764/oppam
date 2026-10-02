<?php

declare(strict_types=1);

namespace App\Actions\Moderation;

use App\Actions\Moderation\Concerns\DecidesModerationItems;
use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Enums\PhotoStatus;
use App\Enums\RejectReason;
use App\Events\Admin\ModerationQueueChanged;
use App\Exceptions\Moderation\ModerationItemUnavailable;
use App\Models\AdminUser;
use App\Models\Media;
use App\Models\ModerationItem;
use App\Models\Profile;
use App\Notifications\Profile\ProfileContentRejected;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Decide one PHOTO item (A04 photo grid, M11). A new photo: APPROVED (others may now see it, under
 * the photo-visibility rules) or REJECTED with the reason (owner sees it labelled; it stops
 * counting towards the 10). A caption-only item (a new caption on an approved photo): approve
 * keeps it, reject clears the caption. A photo deleted meanwhile just closes the item. Audited.
 */
final class DecidePhoto
{
    use DecidesModerationItems;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ModerationItemUnavailable
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, ModerationItem $item, bool $approve, ?RejectReason $reason = null, ?string $note = null): void
    {
        $this->authorizeDecision($admin, $item);

        if ($item->type !== ModerationItemType::Photo) {
            throw new InvalidArgumentException('Not a photo item.');
        }

        $reason ??= RejectReason::Inappropriate;
        $note = $this->validatedNote($note, $approve ? null : $reason);
        $isCaption = is_array($item->fields) && array_key_exists('caption', $item->fields);
        $photo = Media::query()->where('uuid', $item->subject_id)->where('collection_name', Profile::PHOTOS)->first();

        DB::transaction(function () use ($admin, $item, $approve, $reason, $note, $isCaption, $photo): void {
            $this->holdClaim($admin, $item);

            if ($photo !== null && $isCaption && ! $approve) {
                // Only the caption this item is about: a newer one has its own item.
                if ($photo->caption === $item->fields['caption']) {
                    $photo->forceFill(['caption' => null])->save();
                }
            } elseif ($photo !== null && ! $isCaption) {
                $photo->forceFill([
                    'moderation_status' => $approve ? PhotoStatus::Approved : PhotoStatus::Rejected,
                    'rejection_reason' => $approve ? null : $reason->memberMessage(),
                ])->save();
            }

            $this->close($item, $admin, $approve ? ModerationStatus::Approved : ModerationStatus::Rejected,
                $approve ? null : $reason->value, $note);

            $profileCode = Profile::withTrashed()->whereKey($item->profile_id)->value('code');
            $this->audit->record($approve ? 'moderation.photo_approved' : 'moderation.photo_rejected', $item,
                after: ['photo' => $item->subject_id, 'caption_only' => $isCaption, 'reason' => $approve ? null : $reason->value],
                reason: $note, actor: $admin, subjectLabel: is_string($profileCode) ? $profileCode : null);
        });

        ModerationQueueChanged::dispatch(ModerationItemType::Photo);

        if (! $approve && $photo !== null) {
            Profile::query()->whereKey($item->profile_id)->with('user')->first()?->user
                ?->notify(new ProfileContentRejected($isCaption ? __('photo caption') : __('photo'), $reason, $note));
        }
    }
}
