<?php

declare(strict_types=1);

namespace App\Queries\Content;

use App\Data\Content\BannerData;
use App\Data\Content\BranchData;
use App\Data\Content\CounterData;
use App\Data\Content\FaqData;
use App\Data\Content\HomeBlockData;
use App\Data\Content\StoryData;
use App\Data\Content\TestimonialData;
use App\Enums\HomeBlockKey;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\EditorialContent;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use App\Services\Content\ContentImageStore;
use Illuminate\Support\Collection;

/**
 * The only way public pages read A08 content: published rows only, in their set order. Inside a
 * signed preview (PreviewContext) one row appears with its unpublished text, in that request alone.
 */
final class PublicContent
{
    public function __construct(
        private readonly PreviewContext $preview,
        private readonly ContentImageStore $images,
        private readonly HomeCounters $counters,
    ) {}

    /** @return list<BannerData> */
    public function banners(): array
    {
        return $this->rows(Banner::class)->map(fn (Banner $row): BannerData => new BannerData(
            heading: $row->heading,
            copy: $row->copy,
            imageUrl: $this->imageUrl($row->image_path),
            imageWidth: $row->image_width ?? 1920,
            imageHeight: $row->image_height ?? 700,
            alt: (string) $row->alt,
            ctaLabel: $row->cta_label,
            ctaUrl: $row->cta_url,
        ))->values()->all();
    }

    /** @return list<TestimonialData> */
    public function testimonials(): array
    {
        return $this->rows(Testimonial::class)->map(fn (Testimonial $row): TestimonialData => new TestimonialData(
            name: $row->name,
            place: $row->place,
            quote: $row->quote,
            imageUrl: $this->imageUrl($row->image_path),
            imageWidth: $row->image_width ?? 600,
            imageHeight: $row->image_height ?? 600,
        ))->values()->all();
    }

    /** @return list<StoryData> */
    public function stories(): array
    {
        return $this->rows(SuccessStory::class)->map(fn (SuccessStory $row): StoryData => new StoryData(
            slug: $row->slug,
            couple: $row->couple,
            place: $row->place,
            date: __('Married :date', ['date' => $row->wedding_date->format('F Y')]),
            imageUrl: $this->imageUrl($row->image_path),
            imageWidth: $row->image_width ?? 500,
            imageHeight: $row->image_height ?? 500,
            quote: $row->quote,
            bodyHtml: $row->body,
        ))->values()->all();
    }

    /** @return list<BranchData> */
    public function branches(): array
    {
        return $this->rows(Branch::class)->map(fn (Branch $row): BranchData => new BranchData(
            city: $row->city,
            role: $row->role,
            address: $row->address,
            phone: $row->phone,
            tel: $row->tel,
            hours: $row->hours,
            mapUrl: $row->map_url,
        ))->values()->all();
    }

    /** @return list<FaqData> */
    public function faqs(): array
    {
        return $this->rows(FaqItem::class)
            ->map(fn (FaqItem $row): FaqData => new FaqData($row->question, $row->answer))
            ->values()->all();
    }

    /**
     * Sections read by one page, in one query.
     *
     * @return array<string, HomeBlockData> by HomeBlockKey value; an unpublished section is absent
     */
    public function blocks(): array
    {
        $blocks = [];
        foreach ($this->rows(HomeBlock::class) as $row) {
            $blocks[$row->block_key->value] = new HomeBlockData($row->content);
        }

        return $blocks;
    }

    /**
     * The strip of real figures: a figure that is still zero isn't shown.
     *
     * @param  array<string, HomeBlockData>  $blocks  from blocks()
     * @return list<CounterData>
     */
    public function counters(array $blocks): array
    {
        $labels = $blocks[HomeBlockKey::Counters->value] ?? null;
        if ($labels === null) {
            return [];
        }

        $figures = $this->counters->figures();

        return array_values(array_filter([
            new CounterData('images/about/computing.png', $figures['members'], $labels->text('members_label')),
            new CounterData('images/about/heart.png', $figures['marriages'], $labels->text('marriages_label')),
            new CounterData('fa-map-marker', $figures['branches'], $labels->text('branches_label')),
        ], fn (CounterData $counter): bool => $counter->value > 0));
    }

    /** Only a preview can reach here without an image: publishing requires one. */
    private function imageUrl(?string $path): string
    {
        return $path !== null ? $this->images->url($path) : asset('images/photo-processing.svg');
    }

    /**
     * @template T of EditorialContent
     *
     * @param  class-string<T>  $model
     * @return Collection<int, T>
     */
    private function rows(string $model): Collection
    {
        $rows = $model::query()->live()->orderBy('sort_order')->orderBy('id')->get()->toBase()->whereInstanceOf($model)->values();

        $code = $this->preview->codeFor((new $model)->contentType());
        $target = $code === null ? null : $model::query()->where('code', $code)->first();
        if ($target === null) {
            return $rows;
        }

        return $rows->reject(fn ($row): bool => $row->code === $code)
            ->push($target->withDraft())
            ->sortBy(fn ($row): array => [$row->sort_order, $row->id])
            ->whereInstanceOf($model)
            ->values();
    }
}
