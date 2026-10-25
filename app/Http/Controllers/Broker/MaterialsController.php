<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Models\User;
use App\Services\Broker\ReferralPosterRenderer;
use App\Services\Broker\ReferralQrCode;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Downloads for /broker/materials (B.5): the caller's own bureau QR as SVG / PNG and the A4
 * poster PDF. The bureau is always the caller's (BureauGate, `materials.view`) — there is no id in
 * the URL to tamper with (R-M13-6).
 */
final class MaterialsController extends Controller
{
    public function qrSvg(Request $request, BureauGate $gate, ReferralQrCode $qr): Response
    {
        $broker = $this->broker($request, $gate);

        return $this->download($qr->svg($broker, 512), 'image/svg+xml', $broker->code.'-qr.svg');
    }

    public function qrPng(Request $request, BureauGate $gate, ReferralQrCode $qr): Response
    {
        $broker = $this->broker($request, $gate);

        return $this->download($qr->png($broker), 'image/png', $broker->code.'-qr.png');
    }

    public function poster(Request $request, BureauGate $gate, ReferralPosterRenderer $poster): Response
    {
        $broker = $this->broker($request, $gate);

        return $this->download($poster->render($broker), 'application/pdf', $broker->code.'-poster.pdf');
    }

    private function broker(Request $request, BureauGate $gate): Broker
    {
        $user = $request->user('web');

        return $gate->authorize($user instanceof User ? $user : null, BureauPermission::MaterialsView)->broker ?? abort(404);
    }

    private function download(string $body, string $type, string $name): Response
    {
        return response($body, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
