<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\HtmlSanitizer;
use App\Domain\Content\LegalBodyFormatter;
use App\Enums\ContentStatus;
use App\Enums\LegalKind;
use App\Models\Banner;
use App\Models\EditorialContent;
use App\Models\FaqItem;
use App\Models\HomeBlock;
use App\Models\LegalDocument;
use Illuminate\Database\Seeder;

/**
 * The public pages' reference copy (A08): page sections, hero slides, FAQ. Safe everywhere and
 * safe to re-run: a row that exists is left as it is, so an admin's edit is never overwritten.
 * Stories, testimonials and offices aren't here: on production those come from real people.
 * Legal pages get version 1 from the reference copy (P8.1b): not material, not legally reviewed, so
 * nobody is stopped by it and every page says so until counsel signs off a new version.
 */
final class ContentSeeder extends Seeder
{
    public function run(HtmlSanitizer $sanitizer, LegalBodyFormatter $legal): void
    {
        foreach (LegalKind::cases() as $kind) {
            self::seed(new LegalDocument, 'LGL'.$kind->value.'1', [
                'kind' => $kind,
                'version' => 1,
                'title' => $kind->label().' of Oppam Matrimony',
                'body' => $legal->format($sanitizer->cleanDocument(view('legal.reference.'.strtolower($kind->value))->render())),
                'effective_on' => now((string) config('oppam.display_timezone'))->toDateString(),
                'is_material' => false,
                'legally_reviewed' => false,
            ]);
        }

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
