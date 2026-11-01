<?php

declare(strict_types=1);

namespace App\Actions\Admin\Billing;

use App\Actions\Admin\Billing\Concerns\AuthorizesBilling;
use App\Enums\InvestigationStatus;
use App\Exceptions\Admin\BillingRefused;
use App\Models\AdminUser;
use App\Models\BillingInvestigation;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Close an A06 "investigate" row with a note (`billing.refund`, audited). */
final class ResolveInvestigation
{
    use AuthorizesBilling;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws BillingRefused already resolved
     */
    public function handle(AdminUser $admin, BillingInvestigation $row, string $note): BillingInvestigation
    {
        $this->authorizeFor($admin, 'billing.refund');
        $note = $this->validatedReason($note, 'note');

        return DB::transaction(function () use ($admin, $row, $note): BillingInvestigation {
            $locked = BillingInvestigation::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== InvestigationStatus::Open) {
                throw BillingRefused::investigationClosed();
            }

            $locked->forceFill(['status' => InvestigationStatus::Resolved, 'resolved_by_id' => $admin->id, 'resolved_at' => now(), 'resolution_note' => $note])->save();
            $this->audit->record('billing.investigation_resolved', $locked, ['status' => InvestigationStatus::Open->value], ['status' => InvestigationStatus::Resolved->value, 'type' => $locked->type->value], reason: $note, actor: $admin);

            return $locked;
        });
    }
}
