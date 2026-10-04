<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters;

use App\Actions\Admin\Masters\Concerns\EditsMasterList;
use App\Domain\Masters\MasterList;
use App\Domain\Masters\MasterUsage;
use App\Exceptions\Admin\MasterRowInUse;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Delete a master row that nothing uses yet (A11: "deactivate-not-delete when used") — typically
 * one added by mistake. Any profile, preference or child row (a caste under a religion, a district
 * under a state) pointing at it makes this a MasterRowInUse error; the database FKs refuse it too.
 * Audited.
 */
final class DeleteMasterRow
{
    use EditsMasterList;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MasterUsage $usage,
    ) {}

    /**
     * @throws AuthorizationException
     * @throws MasterRowInUse
     */
    public function handle(AdminUser $admin, MasterList $list, ?int $parentId, int $id): void
    {
        $this->authorizeEdit($admin);

        DB::transaction(function () use ($admin, $list, $parentId, $id): void {
            $row = $this->rowInScope($list, $parentId, $id);

            if ($this->usage->isUsed($list, (int) $row->getKey())) {
                throw MasterRowInUse::make($row->label);
            }

            $this->audit->record('masters.deleted', $row, ['list' => $list->key, 'code' => $row->code, 'label' => $row->label],
                actor: $admin, subjectLabel: $this->auditLabel($list, $row));
            $row->delete();
        });
    }
}
