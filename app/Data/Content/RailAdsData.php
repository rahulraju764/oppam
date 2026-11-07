<?php

declare(strict_types=1);

namespace App\Data\Content;

/** What the right rail shows: picked promo and banner, the carousel, and whether to offer an upgrade. */
final readonly class RailAdsData
{
    /** @param  list<AdData>  $carousel */
    public function __construct(
        public ?AdData $promo,
        public ?AdData $banner,
        public array $carousel,
        public bool $showUpgrade,
    ) {}
}
