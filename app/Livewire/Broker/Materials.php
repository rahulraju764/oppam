<?php

declare(strict_types=1);

namespace App\Livewire\Broker;

use App\Data\Content\SeoData;
use App\Domain\Broker\BureauGate;
use App\Enums\BrokerStatus;
use App\Enums\BureauPermission;
use App\Models\User;
use App\Services\Broker\ReferralQrCode;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /broker/materials (B.5 "Materials page", `materials.view`): the bureau code, its copyable
 * referral link, the QR code (SVG / PNG downloads) and the printable A4 poster. Open before KYC
 * (decisions 2026-10-23); until the bureau is ACTIVE a note says referrals count only after
 * approval — codes of bureaus that aren't active are kept for A07 review (R-M13-1).
 */
final class Materials extends Component
{
    public function mount(BureauGate $gate): void
    {
        $gate->authorize($this->user(), BureauPermission::MaterialsView);
    }

    public function render(BureauGate $gate, ReferralQrCode $qr): View
    {
        $broker = $gate->authorize($this->user(), BureauPermission::MaterialsView)->broker ?? abort(404);

        return view('livewire.broker.materials', [
            'broker' => $broker,
            'link' => $qr->link($broker),
            'qrDataUri' => 'data:image/svg+xml;base64,'.base64_encode($qr->svg($broker)),
            'active' => $broker->status === BrokerStatus::Active,
        ])->layout('layouts::broker', ['seo' => SeoData::private('Referral materials | Oppam Matrimony')]);
    }

    private function user(): User
    {
        /** @var User */
        return auth('web')->user();
    }
}
