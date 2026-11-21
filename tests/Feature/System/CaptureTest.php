<?php

declare(strict_types=1);

use App\Domain\System\ScheduleRegistry;
use App\Enums\ScheduledRunStatus;
use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Http\Middleware\RecordWebhookReceipt;
use App\Models\ScheduledTaskRun;
use App\Models\WebhookReceipt;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/*
| P9.2b1 — A12 system health capture: every scheduler run of a task (scheduled_task_runs) and every
| inbound webhook request (webhook_receipts — outcome only, never the body). Capture never stops a
| task or changes a webhook's response.
*/

beforeEach(function (): void {
    $this->seed(Database\Seeders\PlansSeeder::class);
    Illuminate\Support\Facades\Cache::flush();
    Illuminate\Support\Facades\Notification::fake();
    Illuminate\Support\Facades\Queue::fake([App\Jobs\Billing\GenerateInvoicePdf::class]);
    Illuminate\Support\Facades\Event::fake([App\Events\Billing\EntitlementsChanged::class, App\Events\Billing\OrderPaid::class]);
});

function scheduledEvent(string $command = 'reports:snapshot --yesterday', string $expression = '10 0 * * *'): Event
{
    $event = new Event(app(CacheEventMutex::class), $command);
    $event->expression = $expression;

    return $event;
}

// ---- Scheduler runs --------------------------------------------------------------------------------

it('A12 scheduler runs: a task key is the job class, the artisan command, or the closure\'s name', function (): void {
    $schedule = app(Schedule::class);

    expect(ScheduleRegistry::keyOf($schedule->job(new App\Jobs\Engagement\ExpireInterests)))->toBe('ExpireInterests')
        ->and(ScheduleRegistry::keyOf(scheduledEvent("'/usr/bin/php' 'artisan' metrics:snapshot --yesterday")))->toBe('metrics:snapshot --yesterday')
        ->and(ScheduleRegistry::keyOf((new CallbackEvent(app(CacheEventMutex::class), fn () => null))->name('nightly-thing')))->toBe('nightly-thing');
});

it('A12 scheduler runs: start → RUNNING, then OK with its duration', function (): void {
    $task = scheduledEvent();

    event(new ScheduledTaskStarting($task));
    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(ScheduledRunStatus::Running)->and($run->task)->toBe('reports:snapshot --yesterday')
        ->and($run->expression)->toBe('10 0 * * *')->and($run->finished_at)->toBeNull();

    event(new ScheduledTaskFinished($task, 1.234));
    $run->refresh();
    expect($run->status)->toBe(ScheduledRunStatus::Ok)->and($run->duration_ms)->toBe(1234)->and($run->finished_at)->not->toBeNull();
});

it('A12 scheduler runs: a failure keeps the exception CLASS only — never its message', function (): void {
    $task = scheduledEvent();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFailed($task, new RuntimeException('member 9876543210 not found')));

    $run = ScheduledTaskRun::query()->sole();
    expect($run->status)->toBe(ScheduledRunStatus::Failed)->and($run->error)->toBe('RuntimeException')
        ->and($run->duration_ms)->not->toBeNull()
        ->and(json_encode($run->getAttributes()))->not->toContain('9876543210');
});

it('A12 scheduler runs: a task whose filters say "not now" is SKIPPED', function (): void {
    event(new ScheduledTaskSkipped(scheduledEvent()));

    expect(ScheduledTaskRun::query()->sole()->status)->toBe(ScheduledRunStatus::Skipped);
});

it('A12 scheduler runs: two tasks running in the same process finish their own rows', function (): void {
    $a = scheduledEvent('a:run');
    $b = scheduledEvent('b:run');

    event(new ScheduledTaskStarting($a));
    event(new ScheduledTaskStarting($b));
    event(new ScheduledTaskFailed($b, new LogicException));
    event(new ScheduledTaskFinished($a, 0.5));

    expect(ScheduledTaskRun::query()->where('task', 'a:run')->sole()->status)->toBe(ScheduledRunStatus::Ok)
        ->and(ScheduledTaskRun::query()->where('task', 'b:run')->sole()->status)->toBe(ScheduledRunStatus::Failed);
});

it('A12 scheduler runs: capture never stops a task — a broken table is only reported', function (): void {
    Schema::drop('scheduled_task_runs');
    $task = scheduledEvent();

    event(new ScheduledTaskStarting($task));
    event(new ScheduledTaskFinished($task, 0.1));

    expect(true)->toBeTrue();   // nothing thrown
});

// ---- Webhook receipts ------------------------------------------------------------------------------

it('A12 webhooks: an accepted Razorpay delivery is counted, with status and duration only', function (): void {
    $delivery = fakeGateway()->capture((string) checkoutOrder(groom())->gateway_order_id);

    postWebhook($delivery)->assertOk();

    $receipt = WebhookReceipt::query()->sole();
    expect($receipt->provider)->toBe(WebhookProvider::Razorpay)->and($receipt->outcome)->toBe(WebhookOutcome::Accepted)
        ->and($receipt->http_status)->toBe(200)->and($receipt->duration_ms)->toBeGreaterThanOrEqual(0)
        ->and(array_keys($receipt->getAttributes()))->toEqualCanonicalizing(['id', 'provider', 'outcome', 'http_status', 'duration_ms', 'received_at']);
});

it('A12 webhooks: a Razorpay signature failure and a non-event body are told apart', function (): void {
    $delivery = fakeGateway()->capture((string) checkoutOrder(groom())->gateway_order_id);
    postWebhook($delivery, 'forged')->assertStatus(400);

    $body = '{"hello":"world"}';
    postWebhook(['body' => $body, 'signature' => hash_hmac('sha256', $body, hash_hmac('sha256', 'fake-payment-gateway', (string) config('app.key'))), 'event_id' => 'evt_x'])
        ->assertStatus(400);

    expect(WebhookReceipt::query()->orderBy('received_at')->orderBy('id')->pluck('outcome')->all())
        ->toEqualCanonicalizing([WebhookOutcome::RejectedSignature, WebhookOutcome::RejectedBody]);
});

it('A12 webhooks: MSG91 with a wrong secret and a mail bounce with a bad signature are signature rejections', function (): void {
    config(['oppam.comms.msg91_dlr_secret' => 'right-secret']);

    $this->call('POST', memberUrl('/webhooks/msg91/dlr?secret=wrong'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertForbidden();
    $this->call('POST', memberUrl('/webhooks/msg91/dlr?secret=right-secret'), [], [], [], ['CONTENT_TYPE' => 'application/json'], '[]')->assertOk();
    $this->postJson('/webhooks/mail/bounce', ['signature' => ['timestamp' => (string) time(), 'token' => 't', 'signature' => 'bad']])->assertForbidden();

    $rows = WebhookReceipt::query()->get()->map(fn (WebhookReceipt $r): string => $r->provider->value.':'.$r->outcome->value)->all();
    expect($rows)->toEqualCanonicalizing(['MSG91:REJECTED_SIGNATURE', 'MSG91:ACCEPTED', 'MAIL:REJECTED_SIGNATURE']);
});

it('A12 webhooks: a 5xx is an error; a throttled 429 and a non-webhook route are not recorded', function (int $status, ?string $route, ?WebhookOutcome $recorded): void {
    $request = Request::create('/webhooks/razorpay', 'POST');
    $request->setRouteResolver(fn () => $route === null ? null : (new Route('POST', '/x', []))->name($route));

    (new RecordWebhookReceipt)->handle($request, fn () => new Response('', $status));

    expect(WebhookReceipt::query()->first()?->outcome)->toBe($recorded);
})->with([
    'server error' => [500, 'webhooks.razorpay', WebhookOutcome::Error],
    'throttled' => [429, 'webhooks.razorpay', null],
    'not a webhook' => [200, 'home', null],
    'no route' => [200, null, null],
]);

it('A12 webhooks: recording never changes the response — a broken table is only reported', function (): void {
    Schema::drop('webhook_receipts');
    $delivery = fakeGateway()->capture((string) checkoutOrder(groom())->gateway_order_id);

    postWebhook($delivery)->assertOk()->assertJson(['outcome' => 'activated']);
});
