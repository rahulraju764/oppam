<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Domain\Support\TicketQueue;
use App\Enums\SettingKey;
use App\Enums\TicketAuthor;
use App\Enums\TicketStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Services\Admin\Impersonation;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A member answering on their own ticket (/help/{ticket}): only the member's own ticket (another
 * member's is a 404), never a CLOSED one (409). 2 – 5000 characters. The ticket goes back to OPEN —
 * waiting for us — and the queue badge updates live. Shares the hourly ticket limit, counted
 * per reply here so a thread can't be flooded.
 */
final class ReplyToOwnTicket
{
    public function __construct(
        private readonly TicketQueue $queue,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws NotFoundHttpException
     * @throws SafetyRefused
     * @throws ValidationException
     * @throws ImpersonationRestricted
     */
    public function handle(User $member, string $ticketCode, string $body): void
    {
        $this->impersonation->assertAllowed();

        $ticket = SupportTicket::query()->ownedBy($member)->where('code', $ticketCode)->first() ?? throw new NotFoundHttpException;
        if (! $ticket->status->acceptsReplies()) {
            throw SafetyRefused::ticketClosed();
        }

        $data = Validator::make(['body' => trim($body)], ['body' => ['required', 'string', 'min:2', 'max:5000']], [], ['body' => __('message')])->validate();

        $key = 'support-reply:'.$member->id;
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::SupportTicketsMaxPerHour) * 4)) {
            throw SafetyRefused::tooMany();
        }
        $this->limiter->hit($key, 3600);

        DB::transaction(function () use ($ticket, $data): void {
            $locked = SupportTicket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            if (! $locked->status->acceptsReplies()) {
                throw SafetyRefused::ticketClosed();
            }

            $message = new SupportTicketMessage;
            $message->support_ticket_id = $locked->id;
            $message->author = TicketAuthor::Member;
            $message->body = $data['body'];
            $message->save();

            $locked->status = TicketStatus::Open;
            $locked->resolved_at = null;
            $locked->last_activity_at = now();
            $locked->save();

            $this->queue->announce();
        });
    }
}
