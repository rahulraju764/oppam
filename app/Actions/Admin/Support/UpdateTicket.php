<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Support\TicketQueue;
use App\Enums\TicketCategory;
use App\Enums\TicketStatus;
use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Change a ticket's status, category or owner (`support.respond`), audited. Resolving stamps
 * `resolved_at`; re-opening clears it. A category change before the first reply moves the SLA due
 * time to the new category's (counted from when the ticket was opened). The queue updates live.
 */
final class UpdateTicket
{
    public function __construct(
        private readonly TicketQueue $queue,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws AuthorizationException */
    public function status(AdminUser $admin, SupportTicket $ticket, TicketStatus $status): void
    {
        $this->change($admin, $ticket, function (SupportTicket $t) use ($status): void {
            $t->status = $status;
            $t->resolved_at = in_array($status, [TicketStatus::Resolved, TicketStatus::Closed], true) ? ($t->resolved_at ?? now()) : null;
        });
    }

    /** @throws AuthorizationException */
    public function category(AdminUser $admin, SupportTicket $ticket, TicketCategory $category): void
    {
        $this->change($admin, $ticket, function (SupportTicket $t) use ($category): void {
            $t->category = $category;
            if ($t->first_responded_at === null) {
                $t->first_response_due_at = ($t->created_at ?? now())->copy()->addHours($category->slaHours());
            }
        });
    }

    /** @throws AuthorizationException */
    public function assignToMe(AdminUser $admin, SupportTicket $ticket): void
    {
        $this->change($admin, $ticket, fn (SupportTicket $t) => $t->assigned_admin_id = $admin->id);
    }

    /** @param  callable(SupportTicket): mixed  $apply */
    private function change(AdminUser $admin, SupportTicket $ticket, callable $apply): void
    {
        Gate::forUser($admin)->authorize('support.respond');

        DB::transaction(function () use ($admin, $ticket, $apply): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $before = ['status' => $locked->status->value, 'category' => $locked->category->value, 'assigned_admin_id' => $locked->assigned_admin_id];
            $apply($locked);
            $locked->last_activity_at = now();
            $locked->save();

            $this->audit->record('support.ticket.updated', $locked, $before,
                ['status' => $locked->status->value, 'category' => $locked->category->value, 'assigned_admin_id' => $locked->assigned_admin_id],
                actor: $admin, subjectLabel: $locked->code);
            $this->queue->announce();
        });
    }
}
