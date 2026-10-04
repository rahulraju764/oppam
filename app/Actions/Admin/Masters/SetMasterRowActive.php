<?php

declare(strict_types=1);

namespace App\Actions\Admin\Masters;

use App\Actions\Admin\Masters\Concerns\EditsMasterList;
use App\Domain\Masters\MasterList;
use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Activate / deactivate a master row (A11 "deactivate-not-delete when used"). An inactive row
 * disappears from every dropdown (Masters lists active rows only) while profiles that already
 * chose it keep it. Audited; cache flushed by MasterDataObserver.
 */
final class SetMasterRowActive
{
    use EditsMasterList;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @throws AuthorizationException */
    public function handle(AdminUser $admin, MasterList $list, ?int $parentId, int $id, bool $active): void
    {
        $this->authorizeEdit($admin);
        $row = $this->rowInScope($list, $parentId, $id);

        if ($row->is_active === $active) {
            return;
        }

        $row->forceFill(['is_active' => $active])->save();

        $this->audit->record($active ? 'masters.activated' : 'masters.deactivated', $row,
            after: ['list' => $list->key, 'code' => $row->code], actor: $admin, subjectLabel: $this->auditLabel($list, $row));
    }
}
