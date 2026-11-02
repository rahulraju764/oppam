<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Models\User;
use App\Queries\Billing\PlanCatalog;
use App\Queries\Content\FeaturedMembers;
use App\Queries\Content\PublicContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Landing page (template index.php, PRD M12): hero carousel + register form, about, 3 steps,
 * featured members, plans teaser, testimonials. All its copy comes from A08 (PublicContent); a
 * section with nothing published is left out.
 */
final class Home extends Component
{
    public function render(PublicContent $content, FeaturedMembers $featured, PlanCatalog $plans): View
    {
        $viewer = auth('web')->user();
        $blocks = $content->blocks();

        return view('livewire.public.home', [
            'banners' => $content->banners(),
            'blocks' => $blocks,
            'counters' => $content->counters($blocks),
            'members' => $featured->forViewer($viewer instanceof User ? $viewer : null),
            'testimonials' => $content->testimonials(),
            'plans' => $plans->cards(ctaUrl: route('plans')),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Oppam Matrimony | Trusted Kerala Matrimony',
            description: 'Oppam Matrimony helps Malayalis in Kerala to find genuine life partners through verified profiles, secure matchmaking, and trusted connections.',
            keywords: 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms, Online Matrimony Kerala, Marriage Portal Kerala, Trusted Matrimony Kerala',
            ogTitle: 'Find Genuine Kerala Matches with Oppam Matrimony',
            ogDescription: 'Join Oppam Matrimony and discover verified Kerala bride and groom profiles through secure matchmaking and trusted matrimony services.',
        )]);
    }
}
