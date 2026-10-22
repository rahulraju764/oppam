<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Queries\Billing\PlanCatalog;
use App\Support\DemoContent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Membership plans + FAQ (template package.php, PRD M10/M12): Silver / Gold (Most Popular) /
 * Diamond for 1 / 3 / 6 / 12 months with the saving on longer durations, and the comparison table
 * from plan_features. "Purchase Now" opens checkout with the plan KEY and duration — never a price
 * (guests are sent to sign in first by the member route).
 */
final class Plans extends Component
{
    #[Url]
    public int $duration = PlanCatalog::DEFAULT_DURATION;

    public function render(PlanCatalog $catalog): View
    {
        $durations = $catalog->durations();
        $duration = in_array($this->duration, $durations, true) ? $this->duration : ($durations[0] ?? PlanCatalog::DEFAULT_DURATION);

        return view('livewire.public.plans', [
            'plans' => $catalog->cards(ctaUrl: null, duration: $duration, toCheckout: true),
            'durations' => $durations,
            'selectedDuration' => $duration,
            'comparison' => $catalog->comparison(),
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
