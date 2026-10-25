<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Actions\Broker\ManagedProfiles\ExportManagedProfile;
use App\Contracts\BiodataPdfRenderer;
use App\Data\Broker\BiodataData;
use App\Enums\BrokerExportType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Broker\PhotoPackageBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * /broker/profiles/{code}/export/biodata and /export/photos (§11A B.7, B.11). Thin: every decision
 * — ownership, permission, KYC, cap and the unconditional audit row — is ExportManagedProfile's.
 * No `bureau:` route middleware on purpose: a denial there would never reach the Action, and every
 * attempt must be logged (R-M13-13, decisions 2026-10-25). Files leave as attachments, never cached.
 */
final class ManagedProfileExportController extends Controller
{
    private const HEADERS = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store'];

    public function biodata(Request $request, string $code, ExportManagedProfile $export, BiodataPdfRenderer $renderer): Response
    {
        $snapshot = $export->handle($this->login($request), $code, BrokerExportType::BiodataPdf, $request->ip());

        /** @var BiodataData $biodata */
        $biodata = $snapshot->biodata;

        return response($renderer->render($biodata), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$snapshot->profileCode.'-biodata.pdf"',
        ] + self::HEADERS);
    }

    public function photos(Request $request, string $code, ExportManagedProfile $export, PhotoPackageBuilder $builder): BinaryFileResponse
    {
        $snapshot = $export->handle($this->login($request), $code, BrokerExportType::PhotoPackage, $request->ip());

        return response()->download($builder->build($snapshot->photos), $snapshot->profileCode.'-photos.zip', [
            'Content-Type' => 'application/zip',
        ] + self::HEADERS)->deleteFileAfterSend();
    }

    private function login(Request $request): User
    {
        $user = $request->user('web');

        return $user instanceof User ? $user : abort(403);
    }
}
