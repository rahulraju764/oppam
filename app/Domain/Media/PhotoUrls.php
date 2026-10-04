<?php

declare(strict_types=1);

namespace App\Domain\Media;

use App\Data\Media\PhotoView;
use App\Domain\Profile\ProfileVisibility;
use App\Domain\Safety\BlockList;
use App\Enums\PhotoVisibility;
use App\Enums\ProfileStatus;
use App\Models\Media;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Turns a profile's photos into what ONE viewer may receive (M11) — every view that shows photos
 * goes through here, so the privacy rule lives in one place (PhotoAccess):
 *  - the owner gets every photo (pending / rejected labelled) with clear URLs;
 *  - anyone else gets APPROVED photos only, and only blurred URLs unless PhotoAccess allows;
 *  - nobody but the owner gets anything for a profile that isn't ACTIVE (R-M03-3), and a member
 *    who may not open the profile at all (blocked pair, same gender) gets nothing (R-M03-1).
 * A clear URL is never built for a viewer who isn't allowed it.
 */
final class PhotoUrls
{
    public const PLACEHOLDER = '/images/photo-processing.svg';

    public function __construct(
        private readonly PhotoAccess $access,
        private readonly ProfileVisibility $visibility,
        private readonly BlockList $blocks,
    ) {}

    /** @return list<PhotoView> in display order (first = primary) */
    public function forViewer(Profile $owner, ?User $viewer): array
    {
        $isOwner = $this->access->isOwner($owner, $viewer);

        if (! $isOwner && ($owner->status !== ProfileStatus::Active || ($viewer !== null && ! $this->visibility->canView($owner, $viewer)))) {
            return [];
        }

        $clear = $isOwner || $this->access->canSeeClearly($owner, $viewer);

        return $this->photos($owner, approvedOnly: ! $isOwner)
            ->map(fn (Media $media): PhotoView => $this->view($media, $clear, $isOwner))
            ->values()
            ->all();
    }

    /**
     * "Preview as others see it" for the owner (M03): approved photos only, clear when visible to
     * all members, otherwise blurred — what a typical signed-in member without extra access sees.
     *
     * @return list<PhotoView>
     */
    public function forPreview(Profile $owner): array
    {
        $clear = ($owner->privacySetting->photo_visibility ?? PhotoVisibility::AllMembers) === PhotoVisibility::AllMembers;

        return $this->photos($owner, approvedOnly: true)
            ->map(fn (Media $media): PhotoView => $this->view($media, $clear, false))
            ->values()
            ->all();
    }

    /** The primary photo's card URL for this viewer, or null when there is no visible photo. */
    public function primaryCardUrl(Profile $owner, ?User $viewer): ?string
    {
        return ($this->forViewer($owner, $viewer)[0] ?? null)?->cardUrl;
    }

    /**
     * primaryCardUrl() for a whole list of profiles at once (search, lists): one block lookup and
     * one media query for the page instead of two queries per card. Same rules as forViewer():
     * nothing for a profile the viewer may not open (not ACTIVE, same gender, blocked pair, viewer
     * may not browse); approved photos only; clear only where PhotoAccess allows. Load the owners'
     * privacySetting first to avoid one query per card.
     *
     * @param  iterable<Profile>  $owners
     * @return array<string, string|null> profile id => card URL (null = no visible photo)
     */
    public function primaryCardUrls(iterable $owners, User $viewer): array
    {
        $owners = collect($owners);
        $own = $viewer->profile;
        $mayBrowse = $this->visibility->canBrowse($viewer);
        $hidden = $own !== null ? array_flip($this->blocks->hiddenFrom($own)) : [];

        $visible = $owners->filter(fn (Profile $owner): bool => $this->access->isOwner($owner, $viewer)
            || ($mayBrowse && $owner->status === ProfileStatus::Active && $owner->gender !== $own?->gender && ! isset($hidden[(string) $owner->id])));

        $primary = $visible->isEmpty() ? collect() : Media::query()
            ->where('model_type', (new Profile)->getMorphClass())
            ->whereIn('model_id', $visible->map(fn (Profile $p): string => (string) $p->id)->all())
            ->where('collection_name', Profile::PHOTOS)
            ->approved()
            ->orderBy('order_column')
            ->get()
            ->groupBy('model_id')
            ->map(fn (Collection $photos): ?Media => $photos->first());

        $urls = [];
        foreach ($owners as $owner) {
            $media = $primary->get((string) $owner->id);
            $urls[(string) $owner->id] = $media instanceof Media
                ? $this->view($media, $this->access->canSeeClearly($owner, $viewer), false)->cardUrl
                : null;
        }

        return $urls;
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
