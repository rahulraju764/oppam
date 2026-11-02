<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Banner;
use App\Models\Branch;
use App\Models\EditorialContent;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\SuccessStory;
use App\Models\Testimonial;

/** Everything A08 edits: its model, its editable fields and their validation, its preview page. */
enum ContentType: string
{
    case Story = 'stories';
    case Branch = 'branches';
    case Faq = 'faq';
    case Testimonial = 'testimonials';
    case Banner = 'banners';
    case HomeBlock = 'home-blocks';

    public function label(): string
    {
        return match ($this) {
            self::Story => __('Success stories'),
            self::Branch => __('Branches'),
            self::Faq => __('FAQ'),
            self::Testimonial => __('Testimonials'),
            self::Banner => __('Hero banners'),
            self::HomeBlock => __('Home blocks'),
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
        };
    }

    public function hasImage(): bool
    {
        return in_array($this, [self::Story, self::Testimonial, self::Banner], true);
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
            default => [],
        };
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
        };
    }
}
