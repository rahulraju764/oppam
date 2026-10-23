<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Data\Content\SiteContactData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /safety — the Safety Centre (PRD M09): tips (never send money, meet in public, involve family),
 * how to block and report, and emergency contacts. Public: anyone can read it; members get
 * links to their blocked list and in-app help. Support contact details from SiteContactData.
 */
final class SafetyCentre extends Component
{
    public function render(): View
    {
        return view('livewire.public.safety-centre', [
            'site' => SiteContactData::current(),
            'member' => auth('web')->check(),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Safety Centre | Oppam Matrimony',
            description: 'Stay safe while you look for a life partner: never send money, meet in public, involve your family, and report anything that feels wrong.',
            keywords: 'Matrimony safety tips, Online matrimony fraud, Report profile, Oppam Matrimony safety',
            ogTitle: 'Oppam Matrimony Safety Centre',
        )]);
    }
}
