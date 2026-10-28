<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Broker\ImportTemplateBuilder;
use App\Support\Facades\Feature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * /broker/import/template.xlsx and /template.csv (§11A B.9, B.11): the import template, built live
 * from master data. `profiles.import` (route + here) and the `broker.bulk_import` flag.
 */
final class ImportTemplateController extends Controller
{
    public function xlsx(Request $request, BureauGate $gate, ImportTemplateBuilder $builder): Response
    {
        $this->allow($request, $gate);

        return $this->download($builder->xlsx(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'oppam-import-template.xlsx');
    }

    public function csv(Request $request, BureauGate $gate, ImportTemplateBuilder $builder): Response
    {
        $this->allow($request, $gate);

        return $this->download($builder->csv(), 'text/csv; charset=UTF-8', 'oppam-import-template.csv');
    }

    private function allow(Request $request, BureauGate $gate): void
    {
        $user = $request->user('web');
        $gate->authorize($user instanceof User ? $user : null, BureauPermission::ProfilesImport);
        abort_unless(Feature::active(Flag::BrokerBulkImport), 404);
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
