<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Models\Broker;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The printable A4 referral poster (B.5 materials): bureau name, code, link and QR. One page,
 * rendered on request (small and throttled) like the invoice PDF; the QR is embedded as a PNG data
 * URI because dompdf's SVG support is partial.
 */
final class ReferralPosterRenderer
{
    public function __construct(private readonly ReferralQrCode $qr) {}

    public function render(Broker $broker): string
    {
        $broker->loadMissing('district');

        return Pdf::loadView('pdf.broker-poster', [
            'bureau' => $broker->displayName(),
            'district' => $broker->district?->label,
            'code' => $broker->code,
            'link' => $this->qr->link($broker),
            'qr' => 'data:image/png;base64,'.base64_encode($this->qr->png($broker, 600)),
            'site' => (string) config('oppam.site.name'),
        ])->setPaper('a4')->output();
    }
}
