<?php

declare(strict_types=1);

use Laravel\Dusk\Browser;

/*
| P9.2b1 — A12 system health panel in a real browser (`composer dev` running: web, queue worker,
| Reverb, Vite): the lazy panel loads past its skeleton into every card, the System health link is
| in the sidebar, and the page fits 320 – 1440 px. Read-only: nothing is written.
*/

const DUSK_STUCK_EVENT = 'evt_dusk_stuck_replay';

/** P9.2b2 fixtures on the dev DB (the served app's): one open alert, one stuck webhook — removed after. */
function duskHealthCleanup(): void
{
    duskAdminCleanup();
    App\Models\PaymentWebhookEvent::query()->where('event_id', DUSK_STUCK_EVENT)->delete();
    App\Models\SystemAlert::query()->where('value', 4242)->delete();
}

beforeEach(fn () => duskHealthCleanup());
afterEach(fn () => duskHealthCleanup());

it('P9.2b1: the system health panel loads every card from the sidebar and fits every width', function (): void {
    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/'))
            ->clickLink('System health')
            ->waitForText('Scheduled tasks', 15)
            ->assertSee('Queues')
            ->assertSee('Live updates (Reverb)')
            ->assertSee('Webhooks (last 24 hours)')
            ->assertSee('Outbound email & SMS (last 24 hours)')
            ->assertSee('Database')
            ->assertSee('Open Horizon');

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [
            adminDuskUrl('/system/health'),
        ]);
    });
});

it('P9.2b2: the alerts section and the webhook replay dialog (reason, confirm) work and fit every width', function (): void {
    // Resolved (open_key null): can't collide with a real open alert on the dev DB; still listed (resolved this week).
    // Note: the replay below leaves one system.webhook_replayed row in the append-only audit log of the dev DB.
    App\Models\SystemAlert::factory()->resolved()->create(['value' => 4242, 'threshold' => 10]);
    App\Models\PaymentWebhookEvent::factory()->create([
        'event_id' => DUSK_STUCK_EVENT, 'event' => 'payment.authorized', 'processed_at' => null, 'outcome' => null,
        'payload' => json_encode(['entity' => 'event', 'event' => 'payment.authorized', 'contains' => [], 'payload' => []]),
        'failed_attempts' => 1, 'last_failed_at' => now(), 'last_error' => 'RuntimeException', 'created_at' => now()->subMinutes(10),
    ]);
    Illuminate\Support\Facades\Cache::forget(App\Queries\System\WebhookHealth::CACHE_KEY);

    $this->browse(function (Browser $browser): void {
        duskSignInAdmin($browser);

        $browser->visit(adminDuskUrl('/system/health'))
            ->waitForText('Alerts', 15)
            ->assertSee('4,242 jobs failed in the last hour (alert above 10).')
            ->assertSee(DUSK_STUCK_EVENT)
            ->click('@webhook-replay-open')
            ->waitForText('Replay payment webhook')
            ->assertSeeIn('@webhook-replay-target', DUSK_STUCK_EVENT)
            ->type('#webhook-replay-reason', 'Dusk: retry after the outage')
            ->press('Replay webhook')
            ->waitForText('replayed: ignored', 15);

        assertFitsWidths($browser, adminDuskUrl('/favicon.ico'), [adminDuskUrl('/system/health')]);
    });

    expect(App\Models\PaymentWebhookEvent::query()->where('event_id', DUSK_STUCK_EVENT)->value('outcome'))->toBe('ignored');
});
