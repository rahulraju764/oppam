<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Contracts\HtmlSanitizer;
use App\Models\Branch;
use App\Models\SuccessStory;
use App\Models\Testimonial;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/** Demo stories, testimonials and offices. Local only (DatabaseSeeder): none of these people or addresses are real. */
final class DemoContentSeeder extends Seeder
{
    public function run(HtmlSanitizer $sanitizer): void
    {
        /** @var array{stories: list<array{slug: string, couple: string, place: string, date: string, img: string, w: int, h: int, quote: string, story: list<string>}>, testimonials: list<array{name: string, place: string, img: string, quote: string}>, branches: list<array{city: string, role: string, address: string, phone: string, tel: string, hours: string}>} $demo */
        $demo = require database_path('seeders/data/content-demo.php');

        foreach ($demo['stories'] as $index => $story) {
            $body = implode('', array_map(fn (string $paragraph): string => '<p>'.e($paragraph).'</p>', $story['story']));
            ContentSeeder::seed(new SuccessStory, 'STYDEMO'.($index + 1), [
                'slug' => $story['slug'],
                'couple' => $story['couple'],
                'place' => $story['place'],
                'wedding_date' => CarbonImmutable::parse(str_replace('Married ', '1 ', $story['date']))->toDateString(),
                'quote' => $story['quote'],
                'body' => $sanitizer->clean($body),
                'image_path' => $story['img'],
                'image_width' => $story['w'],
                'image_height' => $story['h'],
                'consent_a_at' => now(),
                'consent_b_at' => now(),
                'consent_note' => 'Demo data',
                'sort_order' => $index + 1,
            ]);
        }

        foreach ($demo['testimonials'] as $index => $testimonial) {
            ContentSeeder::seed(new Testimonial, 'TSTDEMO'.($index + 1), [
                'name' => $testimonial['name'],
                'place' => $testimonial['place'],
                'quote' => $testimonial['quote'],
                'image_path' => $testimonial['img'],
                'image_width' => 600,
                'image_height' => 600,
                'consent_at' => now(),
                'sort_order' => $index + 1,
            ]);
        }

        foreach ($demo['branches'] as $index => $branch) {
            ContentSeeder::seed(new Branch, 'BRADEMO'.($index + 1), [...$branch, 'sort_order' => $index + 1]);
        }
    }
}
