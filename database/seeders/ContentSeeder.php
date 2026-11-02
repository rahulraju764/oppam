<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\HtmlSanitizer;
use App\Enums\ContentStatus;
use App\Models\Banner;
use App\Models\EditorialContent;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use Illuminate\Database\Seeder;

/**
 * The public pages' reference copy (A08): page sections, hero slides, FAQ. Safe everywhere and
 * safe to re-run: a row that exists is left as it is, so an admin's edit is never overwritten.
 * Stories, testimonials and offices aren't here: on production those come from real people.
 */
final class ContentSeeder extends Seeder
{
    public function run(HtmlSanitizer $sanitizer): void
    {
        /** @var array{blocks: array<string, array<string, string>>, banners: list<array<string, string>>, faqs: list<array{question: string, answer: string}>} $data */
        $data = require database_path('seeders/data/content.php');

        $order = 0;
        foreach ($data['blocks'] as $key => $content) {
            self::seed(new HomeBlock, 'BLK'.strtoupper($key), ['block_key' => $key, 'content' => $content, 'sort_order' => ++$order]);
        }

        foreach ($data['banners'] as $index => $banner) {
            self::seed(new Banner, 'BNRHERO'.($index + 1), [...$banner, 'image_width' => 1920, 'image_height' => 700, 'sort_order' => $index + 1]);
        }

        foreach ($data['faqs'] as $index => $faq) {
            self::seed(new FaqItem, 'FAQSEED'.($index + 1), [
                'question' => $faq['question'],
                'answer' => $sanitizer->clean('<p>'.e($faq['answer']).'</p>'),
                'sort_order' => $index + 1,
            ]);
        }
    }

    /** @param  array<string, mixed>  $attributes */
    public static function seed(EditorialContent $row, string $code, array $attributes): void
    {
        if ($row->newQuery()->where('code', $code)->exists()) {
            return;
        }

        $row->forceFill([...$attributes, 'code' => $code, 'status' => ContentStatus::Published, 'published_at' => now()])->save();
    }
}
