<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Domain\Broker\BureauGate;
use App\Domain\Broker\Import\CellParser;
use App\Enums\BureauPermission;
use App\Enums\Flag;
use App\Enums\ImportRowStatus;
use App\Http\Controllers\Controller;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Facades\Feature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * /broker/import/{batch}/errors.xlsx (§11A B.9 step 3, B.11): the batch's ERROR and WARNING rows
 * with their original columns, the sheet row number and a messages column — to fix in Excel and
 * upload again. `profiles.import`; a batch of the caller's bureau only (else 404). Every cell is
 * written as plain text with formula-like values neutralised (B.18 "formula injection neutralised
 * on every generated spreadsheet"). Audited.
 */
final class ImportErrorsController extends Controller
{
    public function __invoke(Request $request, string $batch, BureauGate $gate, AuditLogger $audit): Response
    {
        $user = $request->user('web');
        $login = $user instanceof User ? $user : abort(403);
        $staff = $gate->authorize($login, BureauPermission::ProfilesImport);
        abort_unless(Feature::active(Flag::BrokerBulkImport), 404);

        $model = BrokerImportBatch::query()->where('broker_id', $staff->broker_id)->where('code', $batch)->firstOrFail();
        $headers = $model->headers ?? [];

        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Profiles');
        $columns = ['row', ...$headers, 'messages'];
        foreach ($columns as $i => $title) {
            $sheet->setCellValueExplicit([$i + 1, 1], CellParser::neutralise((string) $title), DataType::TYPE_STRING);
        }

        $r = 2;
        BrokerImportRow::query()->where('batch_id', $model->id)
            ->whereIn('status', [ImportRowStatus::Error->value, ImportRowStatus::Warning->value])
            ->orderBy('row_number')
            ->each(function (BrokerImportRow $row) use ($sheet, $headers, &$r): void {
                $raw = is_array($row->data['raw'] ?? null) ? $row->data['raw'] : [];
                $messages = implode(' | ', array_map(
                    fn (array $m): string => ($m['field'] ?? '').': '.($m['text'] ?? ''), $row->messages ?? []));

                $cells = [(string) $row->row_number, ...array_map(fn (string $h): string => (string) ($raw[$h] ?? ''), $headers), $messages];
                foreach ($cells as $i => $value) {
                    $sheet->setCellValueExplicit([$i + 1, $r], CellParser::neutralise($value), DataType::TYPE_STRING);
                }
                $r++;
            });

        $out = fopen('php://temp', 'r+b');
        try {
            (new Xlsx($book))->save($out);
        } finally {
            $book->disconnectWorksheets();   // cyclic refs: free the workbook now, not at the end of the process
        }
        rewind($out);

        $audit->record('broker.import_errors_downloaded', $model, after: ['rows' => $r - 2], actor: $login, subjectLabel: $model->code);

        return response((string) stream_get_contents($out), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$model->code.'-errors.xlsx"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
