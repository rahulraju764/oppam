<?php

declare(strict_types=1);

use App\Actions\Admin\Support\RespondToTicket;
use App\Actions\Admin\Support\SaveCannedResponse;
use App\Actions\Admin\Support\UpdateTicket;
use App\Actions\Support\OpenTicket;
use App\Actions\Support\ReplyToOwnTicket;
use App\Domain\Support\CannedResponseRenderer;
use App\Enums\LoginMethod;
use App\Enums\SettingKey;
use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Events\Admin\AdminQueueCountChanged;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\AuditLog;
use App\Models\LoginEvent;
use App\Models\SupportCannedResponse;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Notifications\Support\GuestTicketReplied;
use App\Notifications\Support\TicketReplied;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P6.2 — A10 support desk: tickets from the contact form and in-app help, SLA, threaded replies,
| internal notes, canned responses with variables (decisions 2026-10-23).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
});

it('contact form: a visitor opens a ticket — code, first message, SLA by category, the queue updates live', function (): void {
    Event::fake([AdminQueueCountChanged::class]);

    $ticket = app(OpenTicket::class)->handle(null, ticketData(['category' => 'PAYMENTS']), TicketSource::ContactForm, '10.0.0.1');

    expect($ticket->code)->toStartWith('TKT')->and($ticket->status)->toBe(TicketStatus::Open)->and($ticket->user_id)->toBeNull()
        ->and($ticket->category)->toBe(TicketCategory::Payments)->and($ticket->context)->toBeNull()
        ->and((int) round($ticket->created_at->diffInHours($ticket->first_response_due_at, true)))->toBe(Settings::int(SettingKey::SupportSlaPaymentsHours))
        ->and(SupportTicketMessage::query()->sole()->body)->toBe('I tried to log in this morning and the code never came.');
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === SupportTicket::QUEUE_KEY && $e->count === 1);
});

it('in-app help: the member’s ticket carries their plan and last actions (names + times only), name and email from the account', function (): void {
    $member = groom();
    $member->forceFill(['email' => 'groom@example.com'])->save();
    foreach (range(1, 3) as $n) {
        (new LoginEvent)->forceFill(['user_id' => $member->id, 'method' => LoginMethod::Otp, 'device_hash' => str_repeat((string) $n, 64), 'created_at' => now()->subHours($n)])->save();
    }

    $ticket = app(OpenTicket::class)->handle($member, ticketData(['name' => '', 'email' => null, 'category' => 'SAFETY']), TicketSource::InApp, '10.0.0.2');

    expect($ticket->user_id)->toBe($member->id)->and($ticket->email)->toBe('groom@example.com')->and($ticket->name)->toBe($member->profile->fullName())
        ->and($ticket->context['plan'])->toBe('Free')
        ->and($ticket->context['actions'])->toHaveCount(3)
        ->and(array_keys($ticket->context['actions'][0]))->toBe(['action', 'at'])
        ->and((int) round($ticket->created_at->diffInHours($ticket->first_response_due_at, true)))->toBe(4);   // safety SLA
});

it('validation: a visitor needs an email; subject and message lengths; unknown category → nothing written', function (array $overrides): void {
    expect(fn () => app(OpenTicket::class)->handle(null, ticketData($overrides), TicketSource::ContactForm, '10.0.0.3'))->toThrow(ValidationException::class);
    expect(SupportTicket::query()->count())->toBe(0);
})->with([
    'no email' => [['email' => null]],
    'bad email' => [['email' => 'not-an-email']],
    'short message' => [['message' => 'help']],
    'unknown category' => [['category' => 'GOSSIP']],
    'no subject' => [['subject' => '']],
]);

it('rule 10: tickets per visitor IP / per member per hour (429); a support session can’t open one', function (): void {
    foreach (range(1, Settings::int(SettingKey::SupportTicketsMaxPerHour)) as $ignored) {
        RateLimiter::hit('support-ticket:ip:10.0.0.4', 3600);
    }
    expect(fn () => app(OpenTicket::class)->handle(null, ticketData(), TicketSource::ContactForm, '10.0.0.4'))
        ->toThrow(fn (SafetyRefused $e) => expect($e->getStatusCode())->toBe(429));

    $member = groom();
    chatImpersonate($member);
    expect(fn () => app(OpenTicket::class)->handle($member, ticketData(), TicketSource::InApp, '10.0.0.5'))->toThrow(ImpersonationRestricted::class);
    expect(SupportTicket::query()->count())->toBe(0);
});

it('member reply: only on my own ticket (another member’s → 404), it re-opens a waiting ticket; a closed ticket → 409', function (): void {
    $member = groom();
    $other = groom('+919800000460');
    $ticket = SupportTicket::factory()->forMember($member)->status(TicketStatus::PendingMember)->create();

    expect(fn () => app(ReplyToOwnTicket::class)->handle($other, $ticket->code, 'Me too'))->toThrow(NotFoundHttpException::class);

    app(ReplyToOwnTicket::class)->handle($member, $ticket->code, 'Still not working');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);

    $ticket->forceFill(['status' => TicketStatus::Closed])->save();
    expect(fn () => app(ReplyToOwnTicket::class)->handle($member, $ticket->code, 'Hello?'))
        ->toThrow(fn (SafetyRefused $e) => expect($e->getStatusCode())->toBe(409));
    expect(SupportTicketMessage::query()->count())->toBe(1);
});

it('agent reply: PENDING_MEMBER, first reply time set, member notified; an internal note changes nothing and tells nobody', function (): void {
    $member = groom();
    $ticket = SupportTicket::factory()->forMember($member)->create();
    $agent = adminWithRole('support');

    app(RespondToTicket::class)->handle($agent, $ticket, 'Internal: checked logs, OTP gateway was down.', true);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)->and($ticket->first_responded_at)->toBeNull();
    Notification::assertNothingSent();

    app(RespondToTicket::class)->handle($agent, $ticket, 'Sorry about that — please try again now.', false);
    expect($ticket->refresh()->status)->toBe(TicketStatus::PendingMember)->and($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->assigned_admin_id)->toBe($agent->id)
        ->and(AuditLog::query()->where('action', 'support.ticket.replied')->sole()->after)->not->toHaveKey('body');
    Notification::assertSentTo($member, TicketReplied::class, fn (TicketReplied $n): bool => $n->ticketCode === $ticket->code);
});

it('agent reply to a contact-form ticket goes by email to the visitor', function (): void {
    $ticket = SupportTicket::factory()->create(['email' => 'visitor@example.com']);

    app(RespondToTicket::class)->handle(adminWithRole('support'), $ticket, 'Thanks — we have fixed your account.', false);

    Notification::assertSentTo(new AnonymousNotifiable, GuestTicketReplied::class,
        fn (GuestTicketReplied $n, array $channels, AnonymousNotifiable $to): bool => $to->routes['mail'] === 'visitor@example.com');
});

it('member never sees internal notes on their ticket page; another member’s ticket is a 404', function (): void {
    $member = groom();
    $ticket = SupportTicket::factory()->forMember($member)->create();
    SupportTicketMessage::factory()->create(['support_ticket_id' => $ticket->id, 'body' => 'Visible question']);
    SupportTicketMessage::factory()->internal()->create(['support_ticket_id' => $ticket->id, 'body' => 'SECRET internal note']);

    $this->actingAs($member)->get(memberUrl('/help/'.$ticket->code))->assertOk()->assertSee('Visible question')->assertDontSee('SECRET internal note');
    $this->actingAs(groom('+919800000461'))->get(memberUrl('/help/'.$ticket->code))->assertNotFound();
});

it('permissions: support.view can read but not reply; status / topic changes are audited; a category change moves an unanswered SLA', function (): void {
    $ticket = SupportTicket::factory()->create(['category' => TicketCategory::Other]);

    expect(fn () => app(RespondToTicket::class)->handle(adminWithRole('moderator'), $ticket, 'Hello there', false))->toThrow(AuthorizationException::class);

    $agent = adminWithRole('support');
    app(UpdateTicket::class)->category($agent, $ticket, TicketCategory::Safety);
    app(UpdateTicket::class)->status($agent, $ticket, TicketStatus::Resolved);

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved)->and($ticket->resolved_at)->not->toBeNull()
        ->and((int) round($ticket->created_at->diffInHours($ticket->first_response_due_at, true)))->toBe(4)
        ->and(AuditLog::query()->where('action', 'support.ticket.updated')->count())->toBe(2);
});

it('canned responses: variables are filled for the ticket; saving needs support.respond and is audited', function (): void {
    $member = groom();
    $ticket = SupportTicket::factory()->forMember($member)->create();
    $agent = adminWithRole('support');

    $saved = app(SaveCannedResponse::class)->save($agent, null, 'Login help', 'Hi {first_name} ({code}), about {ticket} on {plan}: {unknown}. — {agent}', null);
    $text = app(CannedResponseRenderer::class)->render($saved->body, $ticket, $agent);

    expect($text)->toBe('Hi '.$member->profile->first_name.' ('.$member->profile->code.'), about '.$ticket->code.' on Free: {unknown}. — '.$agent->name)
        ->and(AuditLog::query()->where('action', 'support.canned.created')->exists())->toBeTrue();
    expect(fn () => app(SaveCannedResponse::class)->save(adminWithRole('moderator'), null, 'Nope', 'Nope text', null))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveCannedResponse::class)->save($agent, null, 'x', 'y', 'NOT_A_CATEGORY'))->toThrow(ValidationException::class);
    expect(SupportCannedResponse::query()->count())->toBe(1);
});
