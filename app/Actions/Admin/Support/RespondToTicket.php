<?php

declare(strict_types=1);

namespace App\Actions\Admin\Support;

use App\Domain\Support\TicketQueue;
use App\Enums\TicketAuthor;
use App\Enums\TicketStatus;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\Support\GuestTicketReplied;
use App\Notifications\Support\TicketReplied;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * An agent's reply or internal note on a ticket (PRD A10 "threaded view with internal notes"),
 * `support.respond`. 2 – 5000 characters; not on a CLOSED ticket (409).
 *  - Reply: the ticket waits for the member (PENDING_MEMBER); the first reply stops the SLA clock
 *    (`first_responded_at`). A member is told in-app + by email (TicketReplied, the text stays in
 *    the ticket); a visitor from the contact form gets the reply by email (GuestTicketReplied).
 *  - Internal note: admins only — no status change, nobody is told.
 * Audited without the text. The queue badge updates live.
 */
final class RespondToTicket
{
    public function __construct(
        private readonly TicketQueue $queue,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws AuthorizationException|ValidationException|SafetyRefused */
    public function handle(AdminUser $admin, SupportTicket $ticket, string $body, bool $internal): void
    {
        Gate::forUser($admin)->authorize('support.respond');
        $data = Validator::make(['body' => trim($body)], ['body' => ['required', 'string', 'min:2', 'max:5000']], [], ['body' => __('message')])->validate();

        $ticket = DB::transaction(function () use ($admin, $ticket, $data, $internal): SupportTicket {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->acceptsReplies()) {
                throw SafetyRefused::ticketClosed();
            }

            $message = new SupportTicketMessage;
            $message->support_ticket_id = $locked->id;
            $message->author = TicketAuthor::Admin;
            $message->admin_id = $admin->id;
            $message->body = $data['body'];
            $message->is_internal = $internal;
            $message->save();

            if (! $internal) {
                $locked->status = TicketStatus::PendingMember;
                $locked->first_responded_at ??= now();
                $locked->assigned_admin_id ??= $admin->id;
            }
            $locked->last_activity_at = now();
            $locked->save();

            $this->audit->record($internal ? 'support.ticket.note_added' : 'support.ticket.replied', $locked,
                after: ['status' => $locked->status->value, 'length' => mb_strlen($data['body'])], actor: $admin, subjectLabel: $locked->code);
            $this->queue->announce();

            return $locked;
        });

        if ($internal) {
            return;
        }

        $member = $ticket->user;
        if ($member !== null && $member->isActive()) {
            $member->notify(new TicketReplied($ticket->code));
        } elseif ($member === null && $ticket->email !== null) {
            Notification::route('mail', $ticket->email)->notify(new GuestTicketReplied($ticket->code, $ticket->subject, $data['body']));
        }
    }
}
