<?php

declare(strict_types=1);

namespace App\Domain\Broker\Import;

use App\Enums\ImportRowStatus;
use App\Models\BrokerImportBatch;
use App\Models\BrokerImportRow;
use App\ValueObjects\PhoneNumber;

/**
 * Re-checks stored rows of a READY batch after the bureau changed them (§11A B.9 step 3: an inline
 * cell fix, or the fixed errors file re-uploaded) — with the very validator and context the parse
 * used (R-M13-28): the cells come from the row's stored text through the batch's column map, the
 * duplicate context from every row still waiting to be imported, the photo names from the zip names
 * remembered at parse time. A row whose mobile is shared with a changed row is re-checked too (its
 * "repeated in the file" error may come or go). The batch's counts are recounted from the rows.
 * The caller holds the batch lock and the transaction.
 */
final class ImportRowRechecker
{
    public function __construct(private readonly ImportRowValidator $validator) {}

    /**
     * @param  array<int, array<string, string>>  $changed  row number => the row's new raw cells (header => text)
     */
    public function apply(BrokerImportBatch $batch, array $changed): void
    {
        $rows = BrokerImportRow::query()->where('batch_id', $batch->id)
            ->whereIn('status', [ImportRowStatus::Valid, ImportRowStatus::Warning, ImportRowStatus::Error])
            ->orderBy('row_number')->get()->keyBy('row_number');

        $map = is_array($batch->column_map) ? $batch->column_map : [];
        $raw = [];
        foreach ($rows as $number => $row) {
            $raw[$number] = $changed[$number] ?? (is_array($row->data['raw'] ?? null) ? $row->data['raw'] : []);
        }

        $phoneOf = fn (array $cells): ?string => PhoneNumber::tryParse(CellParser::restore((string) ($cells[$map['client_mobile'] ?? ''] ?? '')))?->e164();

        // Rows to re-check: the changed ones, plus any row sharing an old or a new mobile with them.
        $phones = [];
        foreach (array_keys($changed) as $number) {
            if (! isset($rows[$number])) {
                continue;
            }
            $phones[] = $phoneOf($raw[$number]);
            $phones[] = $phoneOf(is_array($rows[$number]->data['raw'] ?? null) ? $rows[$number]->data['raw'] : []);
        }
        $phones = array_filter($phones);
        $recheck = array_keys(array_filter($raw, fn (array $cells, int $n): bool => isset($changed[$n]) || in_array($phoneOf($cells), $phones, true), ARRAY_FILTER_USE_BOTH));

        $context = ImportContext::load(
            $batch->broker_id,
            array_values(array_map(fn (array $cells): string => CellParser::restore((string) ($cells[$map['client_mobile'] ?? ''] ?? '')), $raw)),
            $batch->photos_zip_path !== null ? ($batch->zip_names ?? []) : null,
        );

        foreach ($recheck as $number) {
            $cells = [];
            foreach ($map as $field => $header) {
                $cells[$field] = CellParser::restore((string) ($raw[$number][$header] ?? ''));
            }
            $result = $this->validator->check($cells, $context);

            $rows[$number]->forceFill([
                'data' => ['raw' => $raw[$number], 'values' => $result['values']],
                'status' => $result['status'],
                'messages' => $result['messages'],
            ])->save();
        }

        $this->recount($batch);
    }

    public function recount(BrokerImportBatch $batch): void
    {
        $counts = BrokerImportRow::query()->where('batch_id', $batch->id)
            ->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');

        $batch->forceFill([
            'rows_valid' => (int) ($counts[ImportRowStatus::Valid->value] ?? 0),
            'rows_warning' => (int) ($counts[ImportRowStatus::Warning->value] ?? 0),
            'rows_error' => (int) ($counts[ImportRowStatus::Error->value] ?? 0),
            'rows_imported' => (int) ($counts[ImportRowStatus::Imported->value] ?? 0),
            'rows_skipped' => (int) ($counts[ImportRowStatus::Skipped->value] ?? 0),
        ])->save();
    }
}
