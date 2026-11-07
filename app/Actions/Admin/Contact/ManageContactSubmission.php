<?php

declare(strict_types=1);

namespace App\Actions\Admin\Contact;

use App\Actions\Support\OpenTicket;
use App\Enums\ContactStatus;
use App\Exceptions\Admin\ContactRefused;
use App\Models\AdminUser;
use App\Models\ContactReply;
use App\Models\ContactSubmission;
use App\Models\SupportTicket;
use App\Notifications\Support\ContactReplied;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The A08 contact inbox (P8.1b): assign, reply by email, convert into a support ticket, spam / not
 * spam. Every step needs `support.respond`, runs under the message's row lock, refuses a screen that
 * was looking at an older version and is audited — by code and status only, never the message text
 * or the sender's address (the audit log can't be erased).
 */
final class ManageContactSubmission
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly OpenTicket $tickets,
    ) {}

    /** @throws AuthorizationException|ContactRefused */
    public function assign(AdminUser $admin, ContactSubmission $submission, ?AdminUser $assignee, ?int $expectedVersion = null): ContactSubmission
    {
        if ($assignee !== null && ! Gate::forUser($assignee)->allows('support.respond')) {
            throw ContactRefused::assigneeCannotRespond();
        }

        return $this->step($admin, $submission, $expectedVersion, 'contact.assigned', function (ContactSubmission $locked) use ($assignee): void {
            $this->refuseIfClosed($locked);
            $locked->forceFill([
                'assigned_admin_id' => $assignee?->id,
                'status' => $locked->status->isOpen() ? ($assignee !== null ? ContactStatus::Assigned : ContactStatus::New) : $locked->status,
            ]);
        });
    }

    /**
     * @throws AuthorizationException|ContactRefused
     * @throws ValidationException
     */
    public function reply(AdminUser $admin, ContactSubmission $submission, string $body, ?int $expectedVersion = null): ContactSubmission
    {
        $body = trim($body);
        Validator::make(['reply' => $body], ['reply' => ['required', 'string', 'min:2', 'max:5000']], [], ['reply' => __('reply')])->validate();

        return $this->step($admin, $submission, $expectedVersion, 'contact.replied', function (ContactSubmission $locked) use ($admin, $body): void {
            if ($locked->status === ContactStatus::Spam) {
                throw ContactRefused::isSpam();
            }
            if ($locked->status === ContactStatus::Converted) {
                throw ContactRefused::alreadyConverted();
            }

            $reply = new ContactReply;
            $reply->forceFill(['contact_submission_id' => $locked->id, 'admin_user_id' => $admin->id, 'body' => $body])->save();
            $locked->forceFill([
                'status' => ContactStatus::Replied,
                'replied_at' => now(),
                'assigned_admin_id' => $locked->assigned_admin_id ?? $admin->id,
            ]);

            $email = $locked->email;
            $code = $locked->code;
            DB::afterCommit(fn () => Notification::route('mail', $email)->notify(new ContactReplied($code, $body)));
        });
    }

    /**
     * @throws AuthorizationException|ContactRefused
     * @throws ValidationException the message no longer fits a ticket
     */
    public function convert(AdminUser $admin, ContactSubmission $submission, ?int $expectedVersion = null): SupportTicket
    {
        $ticket = null;
        $this->step($admin, $submission, $expectedVersion, 'contact.converted', function (ContactSubmission $locked) use (&$ticket): void {
            $this->refuseIfClosed($locked);
            $ticket = $this->tickets->fromContact($locked);
            $locked->forceFill(['status' => ContactStatus::Converted, 'support_ticket_id' => $ticket->id]);
        });

        /** @var SupportTicket $ticket */
        return $ticket;
    }

    /** @throws AuthorizationException|ContactRefused */
    public function markSpam(AdminUser $admin, ContactSubmission $submission, bool $spam, ?int $expectedVersion = null): ContactSubmission
    {
        return $this->step($admin, $submission, $expectedVersion, $spam ? 'contact.marked_spam' : 'contact.marked_not_spam', function (ContactSubmission $locked) use ($spam): void {
            if ($locked->status === ContactStatus::Converted) {
                throw ContactRefused::alreadyConverted();
            }
            if ($spam) {
                $locked->forceFill(['status' => ContactStatus::Spam, 'spam_reason' => 'admin']);

                return;
            }
            if ($locked->status === ContactStatus::Spam) {
                $locked->forceFill([
                    'status' => $locked->replied_at !== null ? ContactStatus::Replied : ($locked->assigned_admin_id !== null ? ContactStatus::Assigned : ContactStatus::New),
                    'spam_reason' => null,
                ]);
            }
        });
    }

    /** @param  Closure(ContactSubmission): void  $apply */
    private function step(AdminUser $admin, ContactSubmission $submission, ?int $expectedVersion, string $action, Closure $apply): ContactSubmission
    {
        Gate::forUser($admin)->authorize('support.respond');

        return DB::transaction(function () use ($admin, $submission, $expectedVersion, $action, $apply): ContactSubmission {
            $locked = ContactSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            if ($expectedVersion !== null && $locked->lock_version !== $expectedVersion) {
                throw ContactRefused::changedMeanwhile();
            }

            $before = ['status' => $locked->status->value, 'assigned' => $locked->assigned_admin_id];
            $apply($locked);
            $locked->save();
            $this->audit->record($action, $locked, $before, ['status' => $locked->status->value, 'assigned' => $locked->assigned_admin_id], actor: $admin, subjectLabel: $locked->code);

            return $locked;
        });
    }

    /** @throws ContactRefused */
    private function refuseIfClosed(ContactSubmission $submission): void
    {
        if ($submission->status === ContactStatus::Spam) {
            throw ContactRefused::isSpam();
        }
        if ($submission->status === ContactStatus::Converted) {
            throw ContactRefused::alreadyConverted();
        }
    }
}
