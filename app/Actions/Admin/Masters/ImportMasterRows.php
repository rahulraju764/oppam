<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters;

use App\Actions\Admin\Masters\Concerns\EditsMasterList;
use App\Data\Masters\MasterImportRow;
use App\Domain\Masters\MasterList;
use App\Models\AdminUser;
use App\Models\Masters\MasterRecord;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A11 CSV import with a preview diff. Columns (header row): code, label, label_ml, sort_order,
 * is_active — only code and label are required (word lists: label only). Rows are matched by
 * code within the list / parent being edited: unknown codes are added, known ones updated
 * (label, Malayalam label, order, active). An import NEVER deletes and never changes a code.
 * preview() shows what would happen; apply() parses the same file again on the server, refuses
 * it while any line has an error, writes everything in one transaction and audits the counts.
 */
final class ImportMasterRows
{
    use EditsMasterList;

    public const MAX_ROWS = 2000;

    public const MAX_BYTES = 1_048_576;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return list<MasterImportRow>
     *
     * @throws AuthorizationException
     * @throws ValidationException a file that isn't a usable CSV
     */
    public function preview(AdminUser $admin, MasterList $list, ?int $parentId, string $csv): array
    {
        $this->authorizeEdit($admin);
        $this->assertParent($list, $parentId);

        $existing = $list->query($parentId)->get()->keyBy('code');
        $existingLabels = $existing->mapWithKeys(fn (MasterRecord $row): array => [mb_strtolower($row->label) => $row->code]);
        $seen = [];
        $seenLabels = [];
        $rows = [];

        foreach ($this->parse($csv) as $line => $cells) {
            $errors = [];
            $label = self::unescape(trim((string) ($cells['label'] ?? '')));
            $label = $list->isWordList ? mb_strtolower($label) : $label;
            $code = strtoupper(trim((string) ($cells['code'] ?? '')));
            if ($code === '' && $list->isWordList && $label !== '') {
                // A word already in the list keeps its row (seeded words have their own codes).
                $code = (string) ($existingLabels[mb_strtolower($label)] ?? $this->wordCode($label));
            }
            $labelMl = self::unescape(trim((string) ($cells['label_ml'] ?? '')));
            $sortOrder = $this->intOrNull($cells['sort_order'] ?? null, $errors);
            $isActive = $this->boolOrNull($cells['is_active'] ?? null, $errors);

            if (preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $code) !== 1) {
                $errors[] = __('Code must be capital letters, digits and _, starting with a letter.');
            }
            array_push($errors, ...$this->labelProblems($list, $label));
            $labelOwner = $existingLabels[mb_strtolower($label)] ?? null;
            if ($label !== '' && $labelOwner !== null && $labelOwner !== $code) {
                $errors[] = (string) __('“:label” is already the label of :code.', ['label' => $label, 'code' => $labelOwner]);
            }
            if ($label !== '' && isset($seenLabels[mb_strtolower($label)])) {
                $errors[] = (string) __('“:label” appears twice in the file.', ['label' => $label]);
            }
            $seenLabels[mb_strtolower($label)] = true;
            if (mb_strlen($labelMl) > 120) {
                $errors[] = __('Malayalam label is too long.');
            }
            if (isset($seen[$code])) {
                $errors[] = __('Code :code appears twice in the file.', ['code' => $code]);
            }
            $seen[$code] = true;

            /** @var MasterRecord|null $current */
            $current = $existing->get($code);
            if ($current === null && ! $list->allowsNewRows) {
                $errors[] = (string) __('New rows can\'t be added to this list here.');
            }
            $changes = [];
            if ($current !== null) {
                $changes = array_keys(array_filter([
                    'label' => $current->label !== $label,
                    'label_ml' => array_key_exists('label_ml', $cells) && (string) $current->label_ml !== $labelMl,
                    'sort_order' => $sortOrder !== null && $current->sort_order !== $sortOrder,
                    'is_active' => $isActive !== null && $current->is_active !== $isActive,
                ]));
            }

            $rows[] = new MasterImportRow(
                line: $line, code: $code, label: $label, labelMl: $labelMl === '' ? null : $labelMl,
                sortOrder: $sortOrder, isActive: $isActive,
                status: $errors !== [] ? MasterImportRow::ERROR : ($current === null ? MasterImportRow::NEW : ($changes === [] ? MasterImportRow::SAME : MasterImportRow::UPDATE)),
                changes: $changes, errors: $errors,
            );
        }

        return $rows;
    }

    /**
     * @return array{added: int, updated: int}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function apply(AdminUser $admin, MasterList $list, ?int $parentId, string $csv): array
    {
        $rows = $this->preview($admin, $list, $parentId, $csv);

        if (collect($rows)->contains(fn (MasterImportRow $row): bool => $row->status === MasterImportRow::ERROR)) {
            throw ValidationException::withMessages(['csv' => __('Fix the lines marked as errors first.')]);
        }

        return DB::transaction(function () use ($admin, $list, $parentId, $rows): array {
            $existing = $list->query($parentId)->lockForUpdate()->get()->keyBy('code');
            $next = min(((int) $existing->max('sort_order')) + 10, 60000);   // the column is a small int
            $added = 0;
            $updated = 0;

            foreach ($rows as $row) {
                /** @var MasterRecord|null $current */
                $current = $existing->get($row->code);

                if ($current === null) {
                    /** @var MasterRecord $new */
                    $new = new ($list->model);
                    $new->forceFill([
                        ...$list->scopeAttributes($parentId),
                        'code' => $row->code, 'label' => $row->label, 'label_ml' => $row->labelMl,
                        'sort_order' => $row->sortOrder ?? $next, 'is_active' => $row->isActive ?? true,
                    ])->save();
                    $next += 10;
                    $added++;
                } elseif ($row->status === MasterImportRow::UPDATE) {
                    $current->forceFill([
                        'label' => $row->label,
                        'label_ml' => in_array('label_ml', $row->changes, true) ? $row->labelMl : $current->label_ml,
                        'sort_order' => $row->sortOrder ?? $current->sort_order,
                        'is_active' => $row->isActive ?? $current->is_active,
                    ])->save();
                    $updated++;
                }
            }

            $this->audit->record('masters.imported', null, after: ['list' => $list->key, 'parent' => $parentId, 'added' => $added, 'updated' => $updated],
                actor: $admin, subjectLabel: $list->key);

            return ['added' => $added, 'updated' => $updated];
        });
    }

    /**
     * @return array<int, array<string, string>> line number => column => cell
     *
     * @throws ValidationException
     */
    private function parse(string $csv): array
    {
        if ($csv === '' || strlen($csv) > self::MAX_BYTES || ! mb_check_encoding($csv, 'UTF-8')) {
            throw ValidationException::withMessages(['csv' => __('Upload a UTF-8 CSV file of up to 1 MB.')]);
        }

        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;   // Excel's BOM
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw ValidationException::withMessages(['csv' => __('The file could not be read.')]);
        }
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, escape: '');
        $columns = is_array($header) ? array_map(fn (mixed $c): string => strtolower(trim((string) $c)), $header) : [];

        if (! in_array('label', $columns, true) || array_diff($columns, ['code', 'label', 'label_ml', 'sort_order', 'is_active']) !== []) {
            fclose($handle);
            throw ValidationException::withMessages(['csv' => __('The first row must name the columns: code, label (and optionally label_ml, sort_order, is_active).')]);
        }

        $rows = [];
        $line = 1;
        while (($cells = fgetcsv($handle, escape: '')) !== false) {
            $line++;
            if ($cells === [null] || $cells === ['']) {
                continue;   // blank line
            }
            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                throw ValidationException::withMessages(['csv' => __('Import at most :max rows at a time.', ['max' => self::MAX_ROWS])]);
            }
            $rows[$line] = array_combine($columns, array_pad(array_map(fn (mixed $c): string => (string) $c, array_slice($cells, 0, count($columns))), count($columns), ''));
        }
        fclose($handle);

        return $rows;
    }

    /** Undo the export's formula guard: a leading ' added in front of = + - @ is dropped again. */
    private static function unescape(string $cell): string
    {
        return strlen($cell) > 1 && $cell[0] === "'" && str_contains('=+-@', $cell[1]) ? substr($cell, 1) : $cell;
    }

    /** @param  list<string>  $errors */
    private function intOrNull(?string $value, array &$errors): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! ctype_digit($value) || (int) $value > 60000) {
            $errors[] = (string) __('sort_order must be a whole number up to 60000.');

            return null;
        }

        return (int) $value;
    }

    /** @param  list<string>  $errors */
    private function boolOrNull(?string $value, array &$errors): ?bool
    {
        $value = strtolower(trim((string) $value));

        return match ($value) {
            '' => null,
            '1', 'yes', 'true', 'active' => true,
            '0', 'no', 'false', 'inactive' => false,
            default => (function () use (&$errors): ?bool {
                $errors[] = (string) __('is_active must be yes or no.');

                return null;
            })(),
        };
    }
}
