<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters\Concerns;

use App\Domain\Masters\MasterList;
use App\Domain\Masters\MasterLists;
use App\Models\AdminUser;
use App\Models\Masters\MasterRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;

/**
 * Shared rules of every A11 write: `masters.edit`, a parent that exists for scoped lists
 * (castes → religion, states → country, districts → state), rows looked up inside the list's own
 * scope only (a row of another list / parent is a 404). The cache is flushed by
 * MasterDataObserver on every model write.
 */
trait EditsMasterList
{
    /** @throws AuthorizationException */
    private function authorizeEdit(AdminUser $admin): void
    {
        Gate::forUser($admin)->authorize('masters.edit');
    }

    /** @throws ModelNotFoundException a scoped list without an existing parent */
    private function assertParent(MasterList $list, ?int $parentId): void
    {
        if (! $list->hasParent()) {
            return;
        }

        $parentList = MasterLists::find((string) $list->parentKey);
        if ($parentId === null || $parentList === null || ! $parentList->anyParentQuery()->whereKey($parentId)->exists()) {
            throw (new ModelNotFoundException)->setModel($list->model);
        }
    }

    /** @throws ModelNotFoundException */
    private function rowInScope(MasterList $list, ?int $parentId, int $id): MasterRecord
    {
        $this->assertParent($list, $parentId);

        return $list->query($parentId)->whereKey($id)->firstOrFail();
    }

    /** The code a word-list row gets when none is given (derived from the word). */
    private function wordCode(string $word): string
    {
        return 'W'.strtoupper(substr(sha1($word), 0, 12));
    }

    /**
     * Label rules shared by the editor and the CSV import: required, length, words are letters.
     *
     * @return list<string> problems (empty = fine)
     */
    private function labelProblems(MasterList $list, string $label): array
    {
        $problems = [];

        if ($label === '' || mb_strlen($label) > ($list->isWordList ? 60 : 120)) {
            $problems[] = (string) __('Enter a label of up to :max characters.', ['max' => $list->isWordList ? 60 : 120]);
        }

        if ($list->isWordList && $label !== '' && preg_match("/^[\\p{L}\\p{M}][\\p{L}\\p{M} '-]*$/u", $label) !== 1) {
            $problems[] = (string) __('Enter a word (letters only).');
        }

        return $problems;
    }

    private function auditLabel(MasterList $list, MasterRecord $row): string
    {
        return $list->key.':'.$row->code;
    }
}
