<?php

declare(strict_types=1);

use App\Actions\Admin\Chat\DecideMessageFlag;
use App\Actions\Chat\ReportChatMessages;
use App\Actions\Safety\BlockProfile;
use App\Domain\Safety\AbuseCases;
use App\Enums\AbuseCaseStatus;
use App\Enums\CasePriority;
use App\Enums\MessageFlagDecision;
use App\Enums\MessageFlagSource;
use App\Enums\ReportReason;
use App\Enums\ReportSource;
use App\Enums\SettingKey;
use App\Events\Admin\AdminQueueCountChanged;
use App\Events\Admin\UrgentCaseOpened;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\ReportRefused;
use App\Jobs\Safety\PageOnCallAdmins;
use App\Models\AbuseCase;
use App\Models\Message;
use App\Models\MessageFlag;
use App\Models\Report;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
| P6.2 — report from a profile page (PRD M09) and A10 case aggregation, triage P0/P1/P2,
| auto-escalation and P0 paging (decisions 2026-10-23).
*/

beforeEach(function (): void {
    seedMasters();
    $this->seed(PlansSeeder::class);
    Cache::flush();
    Notification::fake();
    Bus::fake([PageOnCallAdmins::class]);
});

it('M09 / A10: a profile report opens the member’s case (P1 for a fake profile), the queue updates live, nobody is told', function (): void {
    Event::fake([AdminQueueCountChanged::class, UrgentCaseOpened::class]);
    $groom = groom();
    $bride = bride();

    $report = profileReport($bride, $groom, 'FAKE_PROFILE', 'Photos are of a film actor');

    $case = openCaseOf($groom);
    expect($report->source)->toBe(ReportSource::Profile)->and($report->abuse_case_id)->toBe($case->id)
        ->and($case->status)->toBe(AbuseCaseStatus::Open)->and($case->priority)->toBe(CasePriority::P1)
        ->and($case->code)->toStartWith('CASE')
        ->and($case->reports_count)->toBe(1)->and($case->reporters_count)->toBe(1)
        ->and($case->sla_due_at->diffInHours(now(), true))->toBeGreaterThan(23.9)->toBeLessThan(24.1);
    Event::assertDispatched(AdminQueueCountChanged::class, fn (AdminQueueCountChanged $e): bool => $e->queueKey === AbuseCase::QUEUE_KEY && $e->count === 1);
    Event::assertNotDispatched(UrgentCaseOpened::class);
    Notification::assertNothingSentTo($groom);
});

it('A10 triage: asking for money is P0 — on-call admins are paged once (live ping + email/SMS job)', function (): void {
    Event::fake([UrgentCaseOpened::class]);
    $groom = groom();

    profileReport(bride(), $groom, 'ASKING_FOR_MONEY');
    profileReport(reporterNo(1), $groom, 'ASKING_FOR_MONEY');

    $case = openCaseOf($groom);
    expect($case->priority)->toBe(CasePriority::P0)->and($case->paged_at)->not->toBeNull()
        ->and(AbuseCase::query()->count())->toBe(1)->and($case->reports_count)->toBe(2);
    Event::assertDispatchedTimes(UrgentCaseOpened::class, 1);
    Event::assertDispatched(UrgentCaseOpened::class, fn (UrgentCaseOpened $e): bool => $e->broadcastWith() === ['case' => $case->code]);
    Bus::assertDispatchedTimes(PageOnCallAdmins::class, 1);
});

it('A10: a case takes the most urgent priority of its reports and is never lowered; P2 → P1 moves the deadline earlier', function (): void {
    $groom = groom();
    profileReport(bride(), $groom, 'SPAM');
    $case = openCaseOf($groom);
    expect($case->priority)->toBe(CasePriority::P2);
    $p2Due = $case->sla_due_at;

    $second = reporterNo(1);
    profileReport($second, $groom, 'INAPPROPRIATE_PHOTOS');
    // A later P2 report (the same two reporters — below the escalation): the case stays P1.
    $late = Report::factory()->create(['reporter_profile_id' => $second->profile->id, 'reported_profile_id' => $groom->profile->id, 'reason' => ReportReason::Spam]);
    DB::transaction(fn () => app(AbuseCases::class)->attach($late));

    $case->refresh();
    expect($case->priority)->toBe(CasePriority::P1)->and($case->sla_due_at->lt($p2Due))->toBeTrue();
});

it('A10 auto-escalation: 3 distinct reporters in 7 days make the case P0 (dismissed reports and older ones don’t count)', function (): void {
    $groom = groom();
    $this->travelTo(now()->subDays(8));
    profileReport(reporterNo(9), $groom, 'SPAM');   // too old to count
    $this->travelBack();
    openCaseOf($groom)?->forceFill(['status' => AbuseCaseStatus::Closed, 'open_key' => null])->save();

    profileReport(reporterNo(1), $groom, 'SPAM');
    profileReport(reporterNo(2), $groom, 'SPAM');
    expect(openCaseOf($groom)->priority)->toBe(CasePriority::P2)->and(openCaseOf($groom)->escalated_at)->toBeNull();

    profileReport(reporterNo(3), $groom, 'SPAM');

    $case = openCaseOf($groom);
    expect(Settings::int(SettingKey::SafetyEscalateReporters))->toBe(3)
        ->and($case->priority)->toBe(CasePriority::P0)->and($case->escalated_at)->not->toBeNull()->and($case->reporters_count)->toBe(3);
    Bus::assertDispatched(PageOnCallAdmins::class, fn (PageOnCallAdmins $job): bool => $job->caseCode === $case->code);
});

it('F07: a chat report joins the same case as a profile report (A13 flag + A10 case)', function (): void {
    [$groom, $bride] = chatConnected();
    $message = chatSend($groom, $bride, 'Send me 5000 rupees');

    app(ReportChatMessages::class)->handle($bride, $groom->profile, 'ASKING_FOR_MONEY', null, [$message->id]);
    profileReport(reporterNo(1), $groom, 'FAKE_PROFILE');

    $case = openCaseOf($groom);
    expect(Report::query()->where('abuse_case_id', $case->id)->count())->toBe(2)
        ->and(MessageFlag::query()->where('source', MessageFlagSource::Report->value)->count())->toBe(1)
        ->and($case->priority)->toBe(CasePriority::P0);
});

it('A13 escalate: a moderator escalating a flagged message makes the sender’s case P0', function (): void {
    seedAdminRoles();
    [$groom, $bride] = chatConnected();
    $message = chatSend($groom, $bride, 'You will regret this');
    $flag = MessageFlag::factory()->create(['message_id' => $message->id]);

    app(DecideMessageFlag::class)->handle(adminWithRole('moderator'), $flag, MessageFlagDecision::Escalate, null);

    expect(openCaseOf($groom)->priority)->toBe(CasePriority::P0)->and(openCaseOf($groom)->escalated_at)->not->toBeNull();
});

it('409: one open profile report per member reported; 429: `safety.reports_max_per_hour`; nothing written', function (): void {
    $groom = groom();
    $bride = bride();
    profileReport($bride, $groom);

    expect(fn () => profileReport($bride, $groom))->toThrow(fn (ReportRefused $e) => expect($e->getStatusCode())->toBe(409));

    $other = groom('+919800000440');
    foreach (range(2, Settings::int(SettingKey::SafetyReportsMaxPerHour)) as $ignored) {
        RateLimiter::hit('profile-report:'.$bride->profile->id, 3600);
    }
    expect(fn () => profileReport($bride, $other))->toThrow(fn (ReportRefused $e) => expect($e->getStatusCode())->toBe(429));
    expect(Report::query()->count())->toBe(1);
});

it('validation: an unknown reason, “something else” without details, details over 500 → errors, nothing written', function (string $reason, ?string $details): void {
    expect(fn () => profileReport(bride(), groom(), $reason, $details))->toThrow(ValidationException::class);
    expect(Report::query()->count())->toBe(0)->and(AbuseCase::query()->count())->toBe(0);
})->with([
    'unknown reason' => ['HATES_CATS', null],
    'other without details' => ['OTHER', null],
    'details too long' => ['SPAM', str_repeat('x', 501)],
]);

it('security: own profile, a profile I can’t see, a blocked pair → 404; support session refused; nothing written', function (string $case): void {
    $groom = groom();
    $bride = bride();
    $target = $groom;

    match ($case) {
        'self' => $target = $bride,
        'same gender' => $target = bride('+919800000441'),
        'blocked' => app(BlockProfile::class)->handle($groom, $bride->profile),
        'support session' => chatImpersonate($bride),
    };

    expect(fn () => profileReport($bride, $target))->toThrow($case === 'support session' ? ImpersonationRestricted::class : NotFoundHttpException::class);
    expect(Report::query()->count())->toBe(0);
})->with(['self', 'same gender', 'blocked', 'support session']);

it('block, then report: a member can still report someone THEY blocked; the blocked member still can’t report back (404)', function (): void {
    $groom = groom();
    $bride = bride();
    app(BlockProfile::class)->handle($bride, $groom->profile);

    $report = profileReport($bride, $groom, 'ASKING_FOR_MONEY');

    expect($report->abuse_case_id)->toBe(openCaseOf($groom)->id)
        ->and(fn () => profileReport($groom, $bride))->toThrow(NotFoundHttpException::class);
    expect(Report::query()->count())->toBe(1);
});

it('XSS: report details are stored as typed and escaped on the admin case page', function (): void {
    seedAdminRoles();
    $groom = groom();
    profileReport(bride(), $groom, 'OTHER', '<script>alert(1)</script>');
    $admin = adminWithRole('moderator');
    signInAdmin($this, $admin);

    $this->get(adminUrl('/safety/cases/'.openCaseOf($groom)->code))
        ->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    expect(Message::query()->count())->toBe(0);
});
