<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Support\DemoContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Success Stories — grid + long-form anchors (template success-stories.php, PRD M12/M16). */
final class SuccessStories extends Component
{
    public function render(): View
    {
        return view('livewire.public.success-stories', ['stories' => DemoContent::stories()])
            ->layout('layouts::public', ['seo' => new SeoData(
                title: 'Success Stories | Oppam Matrimony',
                description: 'Real couples who met on Oppam Matrimony, in their own words.',
                keywords: 'Kerala Matrimony Success Stories, Oppam Matrimony Couples, Malayali Wedding Stories',
                ogTitle: 'Stories that started on Oppam Matrimony',
            )]);
    }
}
