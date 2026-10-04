<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters;

use App\Actions\Admin\Masters\Concerns\EditsMasterList;
use App\Data\Masters\MasterRowData;
use App\Domain\Masters\MasterList;
use App\Models\AdminUser;
use App\Models\Masters\MasterRecord;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Add or edit a master row (A11). The code is the row's identity: given once when the row is
 * created (UPPER_SNAKE, unique in its list / parent) and never changed after; the label and the
 * Malayalam label are editable. Word lists (profanity) take only the word: it is stored lower
 * case and its code is derived from it. New rows go to the end of the list, active. Audited; the
 * masters cache is flushed by MasterDataObserver, so wizard and search dropdowns change at once.
 */
final class SaveMasterRow
{
    use EditsMasterList;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function create(AdminUser $admin, MasterList $list, ?int $parentId, MasterRowData $data): MasterRecord
    {
        $this->authorizeEdit($admin);
        $this->assertParent($list, $parentId);

        if (! $list->allowsNewRows) {
            throw ValidationException::withMessages(['label' => __('Rows can\'t be added to this list here.')]);
        }

        [$code, $label] = $this->validated($list, $parentId, $data, null);

        return DB::transaction(function () use ($admin, $list, $parentId, $data, $code, $label): MasterRecord {
            /** @var MasterRecord $row */
            $row = new ($list->model);
            $row->forceFill([
                ...$list->scopeAttributes($parentId),
                'code' => $code,
                'label' => $label,
                'label_ml' => $data->labelMl,
                'sort_order' => min(((int) $list->query($parentId)->max('sort_order')) + 10, 60000),   // small-int column
                'is_active' => true,
            ])->save();

            $this->audit->record('masters.created', $row, after: ['list' => $list->key, 'code' => $code, 'label' => $label],
                actor: $admin, subjectLabel: $this->auditLabel($list, $row));

            return $row;
        });
    }

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function update(AdminUser $admin, MasterList $list, ?int $parentId, int $id, MasterRowData $data): MasterRecord
    {
        $this->authorizeEdit($admin);
        $row = $this->rowInScope($list, $parentId, $id);
        [, $label] = $this->validated($list, $parentId, $data, $row);

        $before = ['label' => $row->label, 'label_ml' => $row->label_ml];
        $row->forceFill(['label' => $label, 'label_ml' => $data->labelMl])->save();   // the code never changes

        $this->audit->record('masters.updated', $row, $before, ['label' => $row->label, 'label_ml' => $row->label_ml],
            actor: $admin, subjectLabel: $this->auditLabel($list, $row));

        return $row;
    }

    /**
     * @return array{0: string, 1: string} code (ignored on update), label
     *
     * @throws ValidationException
     */
    private function validated(MasterList $list, ?int $parentId, MasterRowData $data, ?MasterRecord $existing): array
    {
        $label = $list->isWordList ? mb_strtolower($data->label) : $data->label;

        $problems = $this->labelProblems($list, $label);
        if ($problems !== []) {
            throw ValidationException::withMessages(['label' => $problems[0]]);
        }

        if ($data->labelMl !== null && mb_strlen($data->labelMl) > 120) {
            throw ValidationException::withMessages(['labelMl' => __('Keep the Malayalam label under 120 characters.')]);
        }

        $sameLabel = $list->query($parentId)->where('label', $label)->when($existing, fn ($q) => $q->whereKeyNot($existing->getKey()))->exists();
        if ($sameLabel) {
            throw ValidationException::withMessages(['label' => __('This list already has “:label”.', ['label' => $label])]);
        }

        if ($existing !== null) {
            return [$existing->code, $label];
        }

        $code = $list->isWordList && $data->code === '' ? $this->wordCode($label) : $data->code;

        if (preg_match('/^[A-Z][A-Z0-9_]{0,39}$/', $code) !== 1) {
            throw ValidationException::withMessages(['code' => __('Use capital letters, digits and _ (for example KERALA_IYER), starting with a letter.')]);
        }

        if ($list->query($parentId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => __('This code is already used in this list.')]);
        }

        return [$code, $label];
    }
}
