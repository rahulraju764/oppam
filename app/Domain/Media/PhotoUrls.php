<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Data\Media\PhotoView;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns a profile's photos into what ONE viewer may receive (M11) — every view that shows photos
 * goes through here, so the privacy rule lives in one place (PhotoAccess):
 *  - the owner gets every photo (pending / rejected labelled) with clear URLs;
 *  - anyone else gets APPROVED photos only, and only blurred URLs unless PhotoAccess allows.
 * A clear URL is never built for a viewer who isn't allowed it.
 */
final class PhotoUrls
{
    public const PLACEHOLDER = '/images/photo-processing.svg';

    public function __construct(private readonly PhotoAccess $access) {}

    /** @return list<PhotoView> in display order (first = primary) */
    public function forViewer(Profile $owner, ?User $viewer): array
    {
        $isOwner = $this->access->isOwner($owner, $viewer);
        $clear = $isOwner || $this->access->canSeeClearly($owner, $viewer);

        return $this->photos($owner, approvedOnly: ! $isOwner)
            ->map(fn (Media $media): PhotoView => $this->view($media, $clear, $isOwner))
            ->values()
            ->all();
    }

    /** The primary photo's card URL for this viewer, or null when there is no visible photo. */
    public function primaryCardUrl(Profile $owner, ?User $viewer): ?string
    {
        return ($this->forViewer($owner, $viewer)[0] ?? null)?->cardUrl;
    }

    /** @return Collection<int, Media> */
    private function photos(Profile $owner, bool $approvedOnly): Collection
    {
        $query = Media::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('collection_name', Profile::PHOTOS)
            ->orderBy('order_column');

        if ($approvedOnly) {
            $query->approved();
        }

        return $query->get();
    }

    private function view(Media $media, bool $clear, bool $isOwner): PhotoView
    {
        $url = fn (string $conversion): string => $media->hasGeneratedConversion($conversion)
            ? $media->getUrl($conversion)
            : self::PLACEHOLDER;

        $blurred = $url('blurred');

        return new PhotoView(
            uuid: (string) $media->uuid,
            thumbUrl: $clear ? $url('thumb') : $blurred,
            cardUrl: $clear ? $url('card') : $blurred,
            fullUrl: $clear ? $url('full') : $blurred,
            blurred: ! $clear,
            processing: ! $media->hasGeneratedConversion('card'),
            caption: $media->caption,
            status: $isOwner ? $media->moderation_status : null,
            rejectionReason: $isOwner ? $media->rejection_reason : null,
        );
    }
}
