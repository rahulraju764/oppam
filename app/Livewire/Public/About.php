<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Support\DemoContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** About Us — who we are and how we work (template about.php, PRD M12). */
final class About extends Component
{
    public function render(): View
    {
        return view('livewire.public.about', ['values' => DemoContent::values()])
            ->layout('layouts::public', ['seo' => new SeoData(
                title: 'About Us | Oppam Matrimony',
                description: 'Oppam Matrimony is a Kerala matrimony service built around verified profiles, family involvement and privacy. Here is who we are and how we work.',
                keywords: 'About Oppam Matrimony, Kerala Matrimony, Malayali Matrimony, Trusted Matrimony Kerala',
                ogTitle: 'About Oppam Matrimony',
                ogDescription: 'A Kerala matrimony service built on verified profiles and family trust.',
            )]);
    }
}
