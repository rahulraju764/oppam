<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Data\Profile\ProfileCardData;
use App\Support\DemoContent;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Landing page (template index.php, PRD M12): hero carousel + register form, about, 3 steps,
 * top members, plans teaser, testimonials. The hero form becomes <livewire:public.quick-register>
 * in P1.1; the members band becomes a query over ACTIVE, verified, photo-public profiles.
 */
final class Home extends Component
{
    public function render(): View
    {
        $members = array_map(static fn (array $member): ProfileCardData => new ProfileCardData(
            code: '',
            name: $member['name'],
            photoUrl: asset($member['img']),
            place: $member['place'],
        ), DemoContent::homeMembers());

        return view('livewire.public.home', [
            'members' => $members,
            'testimonials' => DemoContent::testimonials(),
            'plans' => DemoContent::plans(ctaUrl: route('plans')),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Oppam Matrimony | Trusted Kerala Matrimony',
            description: 'Oppam Matrimony helps Malayalis in Kerala to find genuine life partners through verified profiles, secure matchmaking, and trusted connections.',
            keywords: 'Kerala Matrimony, Oppam Matrimony, Malayali Matrimony, Kerala Brides, Kerala Grooms, Online Matrimony Kerala, Marriage Portal Kerala, Trusted Matrimony Kerala',
            ogTitle: 'Find Genuine Kerala Matches with Oppam Matrimony',
            ogDescription: 'Join Oppam Matrimony and discover verified Kerala bride and groom profiles through secure matchmaking and trusted matrimony services.',
        )]);
    }
}
