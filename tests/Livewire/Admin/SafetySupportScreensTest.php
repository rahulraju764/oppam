<?php

declare(strict_types=1);

use App\Enums\AbuseCaseStatus;
use App\Enums\CasePriority;
use App\Enums\TicketStatus;
use App\Jobs\Safety\PageOnCallAdmins;
use App\Livewire\Admin\Safety\CaseQueue;
use App\Livewire\Admin\Safety\CaseReview;
use App\Livewire\Admin\Support\CannedResponses;
use App\Livewire\Admin\Support\TicketQueue;
use App\Livewire\Admin\Support\TicketShow;
use App\Models\AbuseCase;
use App\Models\AuditLog;
use App\Models\SupportCannedResponse;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Queries\Admin\WorkQueues;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P6.2 — A10 screens: safety case queue + review (audited reveal, locked props, outcomes by
| permission), support queue, ticket page (internal notes, canned responses), canned editor,
| sidebar / dashboard work queues.
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    Bus::fake([PageOnCallAdmins::class]);
});

it('A10 queue: open cases P0 first, then by deadline; overdue marked; no report text on the list; empty state', function (): void {
    $p1 = AbuseCase::factory()->priority(CasePriority::P1)->create(['sla_due_at' => now()->addHours(2)]);
    $p0 = AbuseCase::factory()->priority(CasePriority::P0)->create(['sla_due_at' => now()->addHours(3)]);
    $late = AbuseCase::factory()->priority(CasePriority::P1)->create(['sla_due_at' => now()->subHour()]);
    $this->actingAs(adminWithRole('moderator'), 'admin');

    $html = Livewire::test(CaseQueue::class)->assertSee('Overdue')->html();
    expect(strpos($html, $p0->code))->toBeLessThan(strpos($html, $late->code))
        ->and(strpos($html, $late->code))->toBeLessThan(strpos($html, $p1->code));

    Livewire::test(CaseQueue::class)->call('show', 'decided')->assertSee('No decisions yet');
});

it('A10 queue / review need safety.reports.view (finance → 403)', function (): void {
    $case = AbuseCase::factory()->create();
    signInAdmin($this, adminWithRole('finance'));

    $this->get(adminUrl('/safety/cases'))->assertForbidden();
    $this->get(adminUrl('/safety/cases/'.$case->code))->assertForbidden();
});

it('A10 review: evidence hidden until the audited reveal; `revealed` is locked; decisions only for permitted outcomes', function (): void {
    [$groom, $bride] = chatConnected();
    $message = chatSend($groom, $bride, 'Pay me first');
    app(App\Actions\Chat\ReportChatMessages::class)->handle($bride, $groom->profile, 'ASKING_FOR_MONEY', null, [$message->id]);
    $case = openCaseOf($groom);
    $this->actingAs(adminWithRole('moderator'), 'admin');

    $review = Livewire::test(CaseReview::class, ['case' => $case->code])
        ->assertSee('Reveal evidence')->assertDontSee('Pay me first')
        ->assertSee('Reveal the evidence before deciding.')->assertDontSee('Warn the member');
    expect(fn () => $review->set('revealed', true))->toThrow(CannotUpdateLockedPropertyException::class);

    $review->call('reveal')->assertSee('Pay me first')->assertSeeHtml('wire:target="decide"')
        ->assertSee('Warn the member')->assertDontSee('Ban the member');      // moderator: no safety.ban
    expect(AuditLog::query()->where('action', 'safety.evidence.revealed')->count())->toBe(1);

    $review->call('decide', 'WARNING')->assertDispatched('toast', type: 'success');
    expect($case->refresh()->status)->toBe(AbuseCaseStatus::Closed);
});

it('A10 review: a support agent (view only) sees no decision buttons, and a forced call is refused', function (): void {
    $case = AbuseCase::factory()->create();
    $this->actingAs(adminWithRole('support'), 'admin');

    Livewire::test(CaseReview::class, ['case' => $case->code])->assertDontSee('Warn the member')
        ->call('decide', 'WARNING')->assertDispatched('toast', type: 'danger');
    expect($case->refresh()->status)->toBe(AbuseCaseStatus::Open);
});

it('A10 review: unknown case code → 404; super admin sees the legal hold controls', function (): void {
    $this->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test(CaseReview::class, ['case' => 'CASE0'])->assertNotFound();
    Livewire::test(CaseReview::class, ['case' => AbuseCase::factory()->create()->code])->assertSee('Place legal hold');
});

it('work queues: safety cases and open tickets cards, for the permitted admins only', function (): void {
    AbuseCase::factory()->create(['sla_due_at' => now()->subHour()]);
    SupportTicket::factory()->create();

    $keys = fn ($admin): array => collect(app(WorkQueues::class)->for($admin))->pluck('key')->all();

    expect($keys(adminWithRole('moderator')))->toContain(AbuseCase::QUEUE_KEY)
        ->and($keys(adminWithRole('support')))->toContain(SupportTicket::QUEUE_KEY)
        ->and($keys(adminWithRole('finance')))->not->toContain(AbuseCase::QUEUE_KEY)->not->toContain(SupportTicket::QUEUE_KEY);
    $card = collect(app(WorkQueues::class)->for(adminWithRole('moderator')))->firstWhere('key', AbuseCase::QUEUE_KEY);
    expect($card->count)->toBe(1)->and($card->breached)->toBeTrue();
});

it('support queue: waiting tickets by first-reply deadline; filters; code / email search; needs support.view', function (): void {
    $a = SupportTicket::factory()->create(['first_response_due_at' => now()->addHours(5), 'email' => 'a@example.com']);
    $b = SupportTicket::factory()->create(['first_response_due_at' => now()->addHour()]);
    SupportTicket::factory()->status(TicketStatus::Resolved)->create(['subject' => 'Already solved']);
    $this->actingAs(adminWithRole('support'), 'admin');

    $html = Livewire::test(TicketQueue::class)->assertDontSee('Already solved')->html();
    expect(strpos($html, $b->code))->toBeLessThan(strpos($html, $a->code));
    Livewire::test(TicketQueue::class)->set('search', 'A@example.com')->assertSee($a->code)->assertDontSee($b->code);

    signInAdmin($this, adminWithRole('finance'));
    $this->get(adminUrl('/support/tickets'))->assertForbidden();
});

it('ticket page: internal notes are marked; a canned response is filled in; replying sends; view-only admins can’t reply', function (): void {
    $member = groom();
    $ticket = SupportTicket::factory()->forMember($member)->create();
    SupportTicketMessage::factory()->create(['support_ticket_id' => $ticket->id, 'body' => 'Help me please']);
    $canned = SupportCannedResponse::factory()->create(['body' => 'Hi {first_name}, about {ticket}.']);
    $agent = adminWithRole('support');
    $this->actingAs($agent, 'admin');

    $page = Livewire::test(TicketShow::class, ['ticket' => $ticket->code])->assertSee('Help me please')
        ->set('canned', $canned->id)->call('useCanned')
        ->assertSet('reply', 'Hi '.$member->profile->first_name.', about '.$ticket->code.'.');
    $page->set('internal', true)->set('reply', 'Checked the OTP logs')->call('send')->assertSee('Internal note');
    $page->set('reply', 'Please try again now')->call('send')->assertDispatched('toast', type: 'success');
    expect($ticket->refresh()->status)->toBe(TicketStatus::PendingMember);
    expect(fn () => $page->set('code', 'TKT1'))->toThrow(CannotUpdateLockedPropertyException::class);

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(TicketShow::class, ['ticket' => $ticket->code])->assertDontSeeHtml('id="ticketReplyBody"')
        ->set('reply', 'Sneaky')->call('send')->assertDispatched('toast', type: 'danger');
    expect(SupportTicketMessage::query()->where('body', 'Sneaky')->exists())->toBeFalse();
});

it('canned responses page: create, edit, delete; needs support.respond', function (): void {
    $this->actingAs(adminWithRole('support'), 'admin');

    $page = Livewire::test(CannedResponses::class)->assertSee('No canned responses yet')
        ->set('title', 'Login trouble')->set('body', 'Hello {first_name}, please try the OTP again.')->call('save')->assertHasNoErrors()
        ->assertSee('Login trouble');
    $id = SupportCannedResponse::query()->sole()->id;
    $page->call('edit', $id)->set('title', 'Login help')->call('save')->assertSee('Login help')
        ->call('delete', $id)->assertSee('No canned responses yet');

    signInAdmin($this, adminWithRole('moderator'));
    $this->get(adminUrl('/support/canned'))->assertForbidden();
});
