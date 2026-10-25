<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Stand-in for B.11 screens whose modules come later (decisions 2026-10-23): the routes exist now
 * — with their real bureau permission and KYC gate (`bureau:profiles.create`, `bureau:profiles.import`)
 * — so the permission matrix and the KYC gate are tested from P7.1. P7.3 / P7.6 replace the
 * component; the route, its name and its middleware stay (P7.3 replaced /profiles/create).
 */
final class ComingSoon extends Component
{
    #[Locked]
    public string $title = '';

    public function mount(): void
    {
        $this->title = match (request()->route()?->getName()) {
            'broker.import' => __('Bulk import'),
            default => __('Coming soon'),
        };
    }

    public function render(): View
    {
        return view('livewire.broker.coming-soon')
            ->layout('layouts::broker', ['seo' => SeoData::private($this->title.' | Oppam Matrimony')]);
    }
}
