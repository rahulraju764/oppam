<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\AdCreative;
use App\Models\Banner;
use App\Models\Branch;
use App\Models\CommunityPage;
use App\Models\EditorialContent;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\LegalDocument;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use Illuminate\Validation\Rule;

/** Everything A08 edits: its model, its editable fields and their validation, its preview page. */
enum ContentType: string
{
    case Story = 'stories';
    case Branch = 'branches';
    case Faq = 'faq';
    case Testimonial = 'testimonials';
    case Banner = 'banners';
    case HomeBlock = 'home-blocks';
    case Legal = 'legal';
    case Ad = 'ads';
    case Community = 'communities';

    public function label(): string
    {
        return match ($this) {
            self::Story => __('Success stories'),
            self::Branch => __('Branches'),
            self::Faq => __('FAQ'),
            self::Testimonial => __('Testimonials'),
            self::Banner => __('Hero banners'),
            self::HomeBlock => __('Home blocks'),
            self::Legal => __('Legal pages'),
            self::Ad => __('Ads'),
            self::Community => __('Community pages'),
        };
    }

    public function singular(): string
    {
        return match ($this) {
            self::Story => __('story'),
            self::Branch => __('branch'),
            self::Faq => __('question'),
            self::Testimonial => __('testimonial'),
            self::Banner => __('banner'),
            self::HomeBlock => __('block'),
            self::Legal => __('legal version'),
            self::Ad => __('ad'),
            self::Community => __('community page'),
        };
    }

    /** @return class-string<EditorialContent> */
    public function model(): string
    {
        return match ($this) {
            self::Story => SuccessStory::class,
            self::Branch => Branch::class,
            self::Faq => FaqItem::class,
            self::Testimonial => Testimonial::class,
            self::Banner => Banner::class,
            self::HomeBlock => HomeBlock::class,
            self::Legal => LegalDocument::class,
            self::Ad => AdCreative::class,
            self::Community => CommunityPage::class,
        };
    }

    public function codePrefix(): string
    {
        return match ($this) {
            self::Story => 'STY',
            self::Branch => 'BRA',
            self::Faq => 'FAQ',
            self::Testimonial => 'TST',
            self::Banner => 'BNR',
            self::HomeBlock => 'BLK',
            self::Legal => 'LGL',
            self::Ad => 'ADV',
            self::Community => 'COM',
        };
    }

    public function hasImage(): bool
    {
        return in_array($this, [self::Story, self::Testimonial, self::Banner, self::Ad], true);
    }

    /** Home blocks are the page's fixed sections: seeded, never added by hand. */
    public function canCreate(): bool
    {
        return $this !== self::HomeBlock;
    }

    /** @return list<string> fields that hold sanitised HTML */
    public function richFields(): array
    {
        return match ($this) {
            self::Story => ['body'],
            self::Faq => ['answer'],
            self::Legal => ['body'],
            self::Community => ['intro'],
            default => [],
        };
    }

    /** Legal pages keep headings, clause anchors and the template's fill-in marks; everything else is basic. */
    public function allowsDocumentMarkup(): bool
    {
        return $this === self::Legal;
    }

    /** @return list<string> date-time fields typed in IST (datetime-local) and stored in UTC */
    public function dateTimeFields(): array
    {
        return $this === self::Ad ? ['starts_at', 'ends_at'] : [];
    }

    /** @return list<string> fields only a new row may set (a version's kind never changes) */
    public function createOnlyFields(): array
    {
        return $this === self::Legal ? ['kind'] : [];
    }

    /**
     * The fields an edit may change, with their rules. A home block's rules depend on its key.
     *
     * @return array<string, list<string>>
     */
    public function rules(?HomeBlockKey $block = null): array
    {
        $order = ['sort_order' => ['required', 'integer', 'min:0', 'max:9999']];

        return $order + match ($this) {
            self::Story => [
                'couple' => ['required', 'string', 'max:120'],
                'place' => ['required', 'string', 'max:80'],
                'wedding_date' => ['required', 'date', 'before_or_equal:today'],
                'quote' => ['required', 'string', 'max:300'],
                'body' => ['required', 'string', 'max:20000'],
            ],
            self::Branch => [
                'city' => ['required', 'string', 'max:80'],
                'role' => ['required', 'string', 'max:40'],
                'address' => ['required', 'string', 'max:300'],
                'phone' => ['required', 'string', 'max:40'],
                'tel' => ['required', 'string', 'regex:/^\+?[0-9]{6,15}$/'],
                'hours' => ['required', 'string', 'max:120'],
                'map_url' => ['nullable', 'string', 'max:500', 'regex:/^https:\/\/[^\s]+$/'],
            ],
            self::Faq => [
                'question' => ['required', 'string', 'max:300'],
                'answer' => ['required', 'string', 'max:5000'],
            ],
            self::Testimonial => [
                'name' => ['required', 'string', 'max:80'],
                'place' => ['required', 'string', 'max:80'],
                'quote' => ['required', 'string', 'max:500'],
            ],
            self::Banner => [
                'heading' => ['required', 'string', 'max:120'],
                'copy' => ['required', 'string', 'max:300'],
                'alt' => ['nullable', 'string', 'max:200'],
                'cta_label' => ['nullable', 'required_with:cta_url', 'string', 'max:40'],
                // A path on this site or an https address: never javascript: or //elsewhere.
                'cta_url' => ['nullable', 'required_with:cta_label', 'string', 'max:500', 'regex:/^(\/(?![\/\\\\])[^\s]*|https:\/\/[^\s]+)$/'],
            ],
            self::Legal => [
                'kind' => ['required', 'string', Rule::enum(LegalKind::class)],
                'title' => ['required', 'string', 'max:120'],
                'summary' => ['nullable', 'string', 'max:500'],
                'body' => ['required', 'string', 'max:200000'],
                'effective_on' => ['required', 'date'],
                'is_material' => ['boolean'],
                'legally_reviewed' => ['boolean'],
            ],
            self::Ad => [
                'slot' => ['required', 'string', Rule::enum(AdSlot::class)],
                'audience' => ['required', 'string', Rule::enum(AdAudience::class)],
                'weight' => ['required', 'integer', 'min:1', 'max:100'],
                'starts_at' => ['nullable', 'date'],
                'ends_at' => ['nullable', 'date', 'after:starts_at'],
                'heading' => ['required', 'string', 'max:80'],
                'copy' => ['nullable', 'string', 'max:200'],
                'alt' => ['nullable', 'string', 'max:200'],
                'cta_label' => ['required', 'string', 'max:40'],
                'cta_url' => ['required', 'string', 'max:500', 'regex:/^(\/(?![\/\\\\])[^\s]*|https:\/\/[^\s]+)$/'],
            ],
            self::Community => [
                'slug' => ['required', 'string', 'max:120', 'regex:/^'.CommunityPage::SLUG_PATTERN.'$/'],
                'heading' => ['required', 'string', 'max:120'],
                'intro' => ['required', 'string', 'max:20000'],
                'meta_description' => ['required', 'string', 'max:200'],
                'religion_id' => ['nullable', 'integer', 'exists:master_religions,id'],
                'caste_id' => ['nullable', 'integer', 'exists:master_castes,id'],
            ],
            self::HomeBlock => collect($block?->fields() ?? [])
                ->mapWithKeys(fn (int $max, string $field): array => ['content.'.$field => ['required', 'string', 'max:'.$max]])
                ->all(),
        };
    }

    /** The real public page a preview of this type renders. */
    public function previewRoute(?HomeBlockKey $block = null): string
    {
        return match ($this) {
            self::Story => 'content.preview.story',
            self::Branch => 'content.preview.branch',
            self::Faq => 'content.preview.faq',
            self::HomeBlock => $block === HomeBlockKey::Values ? 'content.preview.about' : 'content.preview.home',
            self::Testimonial, self::Banner => 'content.preview.home',
            self::Legal => 'content.preview.legal',
            self::Ad => 'content.preview.ad',
            self::Community => 'content.preview.community',
        };
    }
}
