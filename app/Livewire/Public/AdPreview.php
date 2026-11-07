<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * A08 signed preview of an ad (P8.1b): the real right rail (x-ads.rail) with the previewed creative
 * in front, as members would see it. Only reachable through IssueContentPreview's signed link.
 */
final class AdPreview extends Component
{
    public function render(): View
    {
        return view('livewire.public.ad-preview')
            ->layout('layouts::public', ['seo' => SeoData::private(__('Ad preview').' | Oppam Matrimony')]);
    }
}
