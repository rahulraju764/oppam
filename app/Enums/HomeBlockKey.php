<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The fixed text blocks of the public pages (A08 "homepage blocks"). Each has a flat set of text
 * fields with a length limit; icons and images stay in the Blade view, never in the content.
 */
enum HomeBlockKey: string
{
    case About = 'about';
    case Steps = 'steps';
    case Members = 'members';
    case Plans = 'plans';
    case Testimonials = 'testimonials';
    case Counters = 'counters';
    case Values = 'values';

    public function label(): string
    {
        return match ($this) {
            self::About => __('Home: about'),
            self::Steps => __('Home: 3 steps'),
            self::Members => __('Home: featured members'),
            self::Plans => __('Home: plans'),
            self::Testimonials => __('Home: testimonials'),
            self::Counters => __('Counter labels'),
            self::Values => __('About page: values'),
        };
    }

    /** "step1_title" → "Step 1 title". */
    public static function fieldLabel(string $field): string
    {
        return __(ucfirst(str_replace('_', ' ', (string) preg_replace('/(\d)/', ' $1', $field))));
    }

    /** @return array<string, int> field => longest allowed text */
    public function fields(): array
    {
        return match ($this) {
            self::About => ['eyebrow' => 60, 'heading' => 120, 'text' => 500, 'cta_label' => 40],
            self::Steps => [
                'eyebrow' => 60, 'heading' => 120,
                'step1_title' => 60, 'step1_text' => 200,
                'step2_title' => 60, 'step2_text' => 200,
                'step3_title' => 60, 'step3_text' => 200,
            ],
            self::Members => [
                'title_start' => 40, 'title_accent' => 40, 'title_end' => 40,
                'intro_start' => 120, 'intro_highlight' => 40, 'intro_end' => 200, 'cta_label' => 40,
            ],
            self::Plans => ['eyebrow' => 60, 'heading' => 120, 'text' => 300],
            self::Testimonials => ['eyebrow' => 60, 'heading' => 120],
            self::Counters => ['members_label' => 40, 'marriages_label' => 40, 'branches_label' => 40],
            self::Values => [
                'eyebrow' => 60, 'heading' => 120,
                'value1_title' => 80, 'value1_text' => 300,
                'value2_title' => 80, 'value2_text' => 300,
                'value3_title' => 80, 'value3_text' => 300,
                'value4_title' => 80, 'value4_text' => 300,
            ],
        };
    }
}
