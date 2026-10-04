<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters;

use App\Actions\Admin\Masters\Concerns\EditsMasterList;
use App\Domain\Masters\MasterList;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put a list (or one parent's part of it) in a new order (A11 drag-and-drop / move up-down).
 * The ids must be exactly the rows of that scope, each once — so a stale or tampered order can't
 * pull in another list's rows or lose any. sort_order becomes 10, 20, 30… Audited once.
 */
final class ReorderMasterRows
{
    use EditsMasterList;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<int>  $orderedIds
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(AdminUser $admin, MasterList $list, ?int $parentId, array $orderedIds): void
    {
        $this->authorizeEdit($admin);
        $this->assertParent($list, $parentId);

        DB::transaction(function () use ($admin, $list, $parentId, $orderedIds): void {
            $rows = $list->query($parentId)->lockForUpdate()->get()->keyBy(fn ($row): int => (int) $row->getKey());
            $given = array_map('intval', $orderedIds);

            if (count($given) !== count(array_unique($given)) || array_diff($given, $rows->keys()->all()) !== [] || count($given) !== $rows->count()) {
                throw ValidationException::withMessages(['order' => __('The list changed while you were sorting it. Reload and try again.')]);
            }

            foreach ($given as $position => $id) {
                $row = $rows->get($id);
                if ($row !== null && $row->sort_order !== ($position + 1) * 10) {
                    $row->forceFill(['sort_order' => ($position + 1) * 10])->save();
                }
            }

            $this->audit->record('masters.reordered', null, after: ['list' => $list->key, 'parent' => $parentId, 'rows' => count($given)],
                actor: $admin, subjectLabel: $list->key);
        });
    }
}
