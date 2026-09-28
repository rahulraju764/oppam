<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Support\DemoContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/** Our Branches (template branches.php, PRD M12). Offices come from the branches table in P8.1. */
final class Branches extends Component
{
    public function render(): View
    {
        return view('livewire.public.branches', ['branches' => DemoContent::branches()])
            ->layout('layouts::public', ['seo' => new SeoData(
                title: 'Our Branches | Oppam Matrimony',
                description: 'Visit an Oppam Matrimony office in Thrissur, Kochi, Thiruvananthapuram, Kozhikode and across Kerala.',
                keywords: 'Oppam Matrimony Branches, Kerala Matrimony Office, Matrimony Office Thrissur, Matrimony Office Kochi',
                ogTitle: 'Visit an Oppam Matrimony Branch',
                ogDescription: 'Our offices across Kerala — addresses, phone numbers and opening hours.',
            )]);
    }
}
