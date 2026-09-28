<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Support\DemoContent;
use App\Support\Navigation\Navigation;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Membership plans + FAQ (template package.php, PRD M10/M12). "Purchase Now" goes to checkout
 * with the plan KEY once checkout exists (P5.1) — never a price. Until then there is no button.
 */
final class Plans extends Component
{
    public function render(): View
    {
        return view('livewire.public.plans', [
            'plans' => DemoContent::plans(ctaUrl: app(Navigation::class)->url('member.checkout')),
            'faqs' => DemoContent::faqs(),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Membership Packages & FAQ | Oppam Matrimony',
            description: 'Upgrade your membership and unlock premium features, and find answers to common questions about registration, profiles and privacy.',
            keywords: 'Matrimony Membership Plans, Premium Matrimony Kerala, Matrimony Packages, Matrimony FAQ, Kerala Matrimony, Oppam Matrimony',
            ogTitle: 'Choose Your Membership Plan',
            ogDescription: 'Enjoy premium benefits and connect with more compatible profiles.',
        )]);
    }
}
