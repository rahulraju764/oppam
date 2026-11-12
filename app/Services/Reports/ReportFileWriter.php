<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Data\Reports\ReportTableData;
use App\Enums\ExportFormat;
use App\Support\Export\SafeCell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Writes A09 report tables to a CSV or Excel file (P9.1a). Every text cell goes through
 * SafeCell; in Excel each text cell is also written explicitly AS TEXT, so no cell can ever be a
 * formula. CSV: UTF-8 with a BOM (Malayalam names in Excel), one block per table. Excel: one
 * sheet per table.
 */
final class ReportFileWriter
{
    /**
     * @param  iterable<ReportTableData>  $tables
     * @param  resource  $stream  where the file goes (php://output or a temp file)
     * @return int data rows written
     */
    public function write(iterable $tables, ExportFormat $format, $stream, string $title): int
    {
        return $format === ExportFormat::Csv ? $this->csv($tables, $stream, $title) : $this->xlsx($tables, $stream, $title);
    }

    /**
     * @param  iterable<ReportTableData>  $tables
     * @param  resource  $stream
     */
    private function csv(iterable $tables, $stream, string $title): int
    {
        $rows = 0;
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [SafeCell::neutralise($title)], escape: '');
        foreach ($tables as $table) {
            fputcsv($stream, [], escape: '');
            fputcsv($stream, [SafeCell::neutralise($table->title)], escape: '');
            fputcsv($stream, array_map(SafeCell::neutralise(...), $table->headers), escape: '');
            foreach ($table->rows as $row) {
                fputcsv($stream, array_map(SafeCell::neutralise(...), $row), escape: '');
                $rows++;
            }
        }

        return $rows;
    }

    /**
     * @param  iterable<ReportTableData>  $tables
     * @param  resource  $stream
     */
    private function xlsx(iterable $tables, $stream, string $title): int
    {
        $book = new Spreadsheet;
        $book->getProperties()->setTitle(mb_substr($title, 0, 100));
        // A cover sheet carrying the report title and period, as the CSV's first line does.
        $cover = $book->getSheet(0);
        $cover->setTitle('Report');
        $cover->getCell('A1')->setValueExplicit((string) SafeCell::neutralise($title), DataType::TYPE_STRING);
        $rows = 0;
        $n = 0;

        foreach ($tables as $table) {
            $n++;   // a running number keeps every sheet title unique (Excel requires it)
            $sheet = $book->createSheet();
            $sheet->setTitle(mb_substr((string) preg_replace('/[\\\\\/?*\[\]:\']/', ' ', $table->title), 0, 27).' '.$n);
            $r = 1;
            foreach ([$table->headers, ...$table->rows] as $line) {
                foreach ($line as $c => $value) {
                    $cell = $sheet->getCell([$c + 1, $r]);
                    if (is_int($value) || is_float($value)) {
                        $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
                    } else {
                        $cell->setValueExplicit((string) SafeCell::neutralise($value), DataType::TYPE_STRING);
                    }
                }
                $r++;
            }
            $rows += count($table->rows);
        }

        (new Xlsx($book))->save($stream);

        return $rows;
    }
}
