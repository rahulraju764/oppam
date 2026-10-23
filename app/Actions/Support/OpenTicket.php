<?php

declare(strict_types=1);

namespace App\Actions\Support;

use App\Data\Support\TicketRequestData;
use App\Domain\Support\TicketQueue;
use App\Enums\AuditActorType;
use App\Enums\SettingKey;
use App\Enums\TicketAuthor;
use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\User;
use App\Queries\Billing\CurrentMembership;
use App\Services\Admin\Impersonation;
use App\Services\Support\TicketCodeGenerator;
use App\Support\Facades\Settings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Open a support ticket (PRD A10 "support tickets from contact form, in-app help"):
 *  - from the contact form, by a visitor (name + email required) or a signed-in member;
 *  - from in-app help, by a signed-in member only — the ticket then carries the member's plan and
 *    their last 20 account actions (`context`, admins only: action names and times, nothing else).
 * Category from TicketCategory; subject 3 – 150, message 10 – 5000 characters. The first reply is
 * due after the category's SLA. `support.tickets_max_per_hour` per member — or per IP for a
 * visitor (429). The support queue badge updates live. Never during an admin support session.
 */
final class OpenTicket
{
    public const CONTEXT_ACTIONS = 20;

    public function __construct(
        private readonly TicketCodeGenerator $codes,
        private readonly TicketQueue $queue,
        private readonly CurrentMembership $membership,
        private readonly RateLimiter $limiter,
        private readonly Impersonation $impersonation,
    ) {}

    /**
     * @throws ValidationException
     * @throws SafetyRefused too many tickets this hour
     * @throws ImpersonationRestricted
     */
    public function handle(?User $member, TicketRequestData $data, TicketSource $source, string $ip): SupportTicket
    {
        if ($member !== null) {
            $this->impersonation->assertAllowed();
        }
        if ($source === TicketSource::InApp && $member === null) {
            throw new InvalidArgumentException('In-app help needs a signed-in member.');
        }

        $name = trim($data->name) !== '' ? trim($data->name) : ($member?->profile?->fullName() ?? '');
        $email = $data->email !== null && trim($data->email) !== '' ? Str::lower(trim($data->email)) : $member?->email;
        $phone = $data->phone !== null && trim($data->phone) !== '' ? trim($data->phone) : null;

        $valid = Validator::make([
            'name' => $name, 'email' => $email, 'phone' => $phone, 'category' => $data->category,
            'subject' => trim($data->subject), 'message' => trim($data->message),
        ], [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'email' => [$member === null ? 'required' : 'nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{10,16}$/'],
            'category' => ['required', Rule::enum(TicketCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ], [], [
            'name' => __('name'), 'email' => __('email'), 'phone' => __('phone'), 'category' => __('topic'),
            'subject' => __('subject'), 'message' => __('message'),
        ])->validate();

        $key = 'support-ticket:'.($member !== null ? 'u:'.$member->id : 'ip:'.$ip);
        if ($this->limiter->tooManyAttempts($key, Settings::int(SettingKey::SupportTicketsMaxPerHour))) {
            throw SafetyRefused::tooMany();
        }
        $this->limiter->hit($key, 3600);

        $category = TicketCategory::from($valid['category']);
        $context = $source === TicketSource::InApp ? $this->context($member) : null;

        return DB::transaction(function () use ($member, $valid, $source, $category, $context): SupportTicket {
            $ticket = new SupportTicket;
            $ticket->code = $this->codes->next();
            $ticket->source = $source;
            $ticket->category = $category;
            $ticket->status = TicketStatus::Open;
            $ticket->user_id = $member?->id;
            $ticket->name = $valid['name'];
            $ticket->email = $valid['email'];
            $ticket->phone = $valid['phone'];
            $ticket->subject = $valid['subject'];
            $ticket->context = $context;
            $ticket->first_response_due_at = now()->addHours($category->slaHours());
            $ticket->last_activity_at = now();
            $ticket->save();

            $message = new SupportTicketMessage;
            $message->support_ticket_id = $ticket->id;
            $message->author = TicketAuthor::Member;
            $message->body = $valid['message'];
            $message->save();

            $this->queue->announce();

            return $ticket;
        });
    }

    /**
     * In-app help: the member's plan and last account actions (logins + their own audited actions).
     *
     * @return array{plan: string, profile_status: string|null, actions: list<array{action: string, at: string}>}
     */
    private function context(User $member): array
    {
        $profile = $member->profile;
        $logins = LoginEvent::query()->where('user_id', $member->id)->latest('created_at')->limit(self::CONTEXT_ACTIONS)->get(['method', 'created_at'])
            ->map(fn (LoginEvent $e): array => ['action' => 'login.'.strtolower($e->method->value), 'at' => $e->created_at->toIso8601String()]);
        $audited = AuditLog::query()->where('actor_type', AuditActorType::User->value)->where('actor_id', $member->id)
            ->latest('created_at')->limit(self::CONTEXT_ACTIONS)->get(['action', 'created_at'])
            ->map(fn (AuditLog $l): array => ['action' => $l->action, 'at' => $l->created_at->toIso8601String()]);

        return [
            'plan' => $profile !== null ? ($this->membership->current($profile)?->plan->name ?? 'Free') : 'Free',
            'profile_status' => $profile?->status->value,
            'actions' => $logins->concat($audited)->sortByDesc('at')->take(self::CONTEXT_ACTIONS)->values()->all(),
        ];
    }
}
