<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\DocumentVault;
use App\Domain\Broker\KycDocumentAccess;
use App\Models\AdminUser;
use App\Models\BrokerKyc;
use App\Services\Audit\AuditLogger;
use App\Services\Verification\DocumentWatermarker;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * A bureau's PAN image, for the A07 KYC queue only (PRD §11A B.12 / B.18 "PAN images viewed by
 * admins through signed, audited URLs only"). A 10-minute signed link (`signed`) that works only in
 * the admin session it was granted to (KycDocumentAccess) — anything else is a 404; never navigated
 * to or framed (Sec-Fetch-Dest). Images leave watermarked with the admin's email + time; PDFs go to
 * the pdf.js viewer. Every fetch is audited. `no-store`, `nosniff`, sandbox CSP.
 */
final class BrokerKycDocumentController
{
    private const REFUSED_DESTINATIONS = ['document', 'iframe', 'frame', 'embed', 'object'];

    public function __invoke(
        Request $request,
        string $broker,
        KycDocumentAccess $access,
        DocumentVault $vault,
        DocumentWatermarker $watermarker,
        AuditLogger $audit,
    ): Response {
        Gate::authorize('brokers.kyc');

        /** @var AdminUser $admin */
        $admin = $request->user('admin');
        $kycId = $access->grantedKycId($broker);

        if ($kycId === null || in_array(strtolower((string) $request->header('Sec-Fetch-Dest')), self::REFUSED_DESTINATIONS, true)) {
            abort(404);
        }

        $kyc = BrokerKyc::query()->whereKey($kycId)->whereHas('broker', fn ($q) => $q->where('code', $broker))->first();
        if ($kyc === null || $kyc->pan_image_path === null) {
            abort(404);
        }

        $pdf = $kyc->pan_image_mime === 'application/pdf';

        try {
            $bytes = $vault->get($kyc->pan_image_path);
            $body = $pdf ? $bytes : $watermarker->apply($bytes, __(':email · :time IST · access logged', [
                'email' => $admin->email,
                'time' => now()->timezone((string) config('oppam.display_timezone'))->format('d M Y H:i'),
            ]));
        } catch (RuntimeException $e) {
            report($e);
            abort(404);
        }

        $audit->record('brokers.kyc_pan_viewed', $kyc, after: ['mime' => (string) $kyc->pan_image_mime], actor: $admin, subjectLabel: $broker);

        return response($body, 200, [
            'Content-Type' => $pdf ? 'application/pdf' : 'image/jpeg',
            'Content-Disposition' => 'inline; filename="pan.'.($pdf ? 'pdf' : 'jpg').'"',
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Referrer-Policy' => 'no-referrer',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }
}
