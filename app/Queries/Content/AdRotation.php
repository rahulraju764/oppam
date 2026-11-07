<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Data\Content\AdData;
use App\Data\Content\RailAdsData;
use App\Enums\AdAudience;
use App\Enums\AdSlot;
use App\Enums\ContentType;
use App\Models\AdCreative;
use App\Models\Profile;
use App\Models\User;
use App\Services\Content\ContentImageStore;
use Illuminate\Support\Collection;

/**
 * The right rail's ads (A08 ad slots): published creatives inside their window, for the viewer's
 * audience (free / paid), one promo and one banner picked by weight, every carousel slide in order.
 * One query for the creatives, one for the viewer's plan flag, once per request.
 */
final class AdRotation
{
    /** Carousel slides shown at most. */
    public const MAX_SLIDES = 6;

    private ?RailAdsData $resolved = null;

    public function __construct(
        private readonly PreviewContext $preview,
        private readonly ContentImageStore $images,
    ) {}

    public function forViewer(?User $viewer): RailAdsData
    {
        if ($this->resolved !== null && ! $this->preview->active()) {
            return $this->resolved;
        }

        $paid = $viewer !== null && (bool) Profile::query()->where('user_id', $viewer->id)->value('is_premium');
        $creatives = AdCreative::query()->live()->running()
            ->whereIn('audience', AdAudience::for($paid))
            ->orderBy('sort_order')->orderBy('id')->limit(100)->get();

        $previewed = $this->previewed();
        if ($previewed !== null) {
            $creatives = $creatives->reject(fn (AdCreative $ad): bool => $ad->code === $previewed->code)->prepend($previewed);
        }

        $bySlot = fn (AdSlot $slot): Collection => $creatives->filter(fn (AdCreative $ad): bool => $ad->slot === $slot)->values();

        $rail = new RailAdsData(
            promo: $this->pick($bySlot(AdSlot::Promo), $previewed),
            banner: $this->pick($bySlot(AdSlot::Banner), $previewed),
            carousel: $bySlot(AdSlot::Carousel)->filter(fn (AdCreative $ad): bool => $ad->image_path !== null)->take(self::MAX_SLIDES)->map(fn (AdCreative $ad): AdData => $this->data($ad))->values()->all(),
            showUpgrade: ! $paid,
        );

        return $previewed === null ? $this->resolved = $rail : $rail;
    }

    /**
     * One creative, each with a chance proportional to its weight. A previewed one always wins.
     *
     * @param  Collection<int, AdCreative>  $candidates
     */
    private function pick(Collection $candidates, ?AdCreative $previewed): ?AdData
    {
        if ($candidates->isEmpty()) {
            return null;
        }
        if ($previewed !== null && $candidates->contains(fn (AdCreative $ad): bool => $ad->code === $previewed->code)) {
            return $this->data($previewed);
        }

        $total = (int) $candidates->sum(fn (AdCreative $ad): int => max(1, $ad->weight));
        $ticket = random_int(1, max(1, $total));
        foreach ($candidates as $ad) {
            $ticket -= max(1, $ad->weight);
            if ($ticket <= 0) {
                return $this->data($ad);
            }
        }

        return $this->data($candidates->last());
    }

    private function previewed(): ?AdCreative
    {
        $code = $this->preview->codeFor(ContentType::Ad);

        return $code === null ? null : AdCreative::query()->where('code', $code)->first()?->withDraft();
    }

    private function data(AdCreative $ad): AdData
    {
        return new AdData(
            heading: $ad->heading,
            copy: $ad->copy,
            ctaLabel: (string) $ad->cta_label,
            ctaUrl: (string) $ad->cta_url,
            external: str_starts_with((string) $ad->cta_url, 'https://'),
            imageUrl: $ad->image_path !== null ? $this->images->url($ad->image_path) : null,
            imageWidth: $ad->image_width ?? 800,
            imageHeight: $ad->image_height ?? 800,
            alt: (string) $ad->alt,
        );
    }
}
