<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\RunIntegrationCheck;
use App\Livewire\Admin\Settings\Integrations;
use App\Models\AuditLog;
use App\Services\Health\Msg91Probe;
use App\Services\Health\TurnstileProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| P8.4 — A15 integrations: status from config with secrets masked (never shown), and Test buttons
| that make real read-only calls (settings.edit, 10 an hour, audited).
*/

beforeEach(function (): void {
    seedAdminRoles();
    Cache::flush();
    config([
        'services.msg91.auth_key' => 'msg91-secret-auth-key-123456',
        'services.turnstile.site_key' => '0x4AAAAAAAsitekey99',
        'services.turnstile.secret_key' => '0x4AAAAAAAsecretKEYdoNotShow',
        'services.razorpay.test.key_secret' => 'rzp-secret-doNotShow',
    ]);
    $this->actingAs($this->admin = adminWithRole('super_admin'), 'admin');
});

it('A15 integrations: every service is listed with its driver; secrets never reach the page (masked public ids only)', function (): void {
    Livewire::test(Integrations::class)->assertOk()
        ->assertSee('Payments (Razorpay)')->assertSee('SMS (MSG91)')->assertSee('Email')->assertSee('File storage')
        ->assertSee('Live updates (WebSockets)')->assertSee('Bot check (Turnstile)')
        ->assertSee('0x4A••••ey99')
        ->assertDontSee('msg91-secret-auth-key-123456')->assertDontSee('0x4AAAAAAAsecretKEYdoNotShow')->assertDontSee('rzp-secret-doNotShow')
        ->assertSeeHtml('dusk="probe-razorpay"');
});

it('A15 integrations: each Test makes its real check — fake payments say so, MSG91 balance, Turnstile secret, storage round trip, mail to the admin only', function (): void {
    Mail::fake();
    Storage::fake('public');
    Storage::fake('local');
    Http::fake([
        Msg91Probe::BALANCE_URL.'*' => Http::response('1520', 200),
        TurnstileProbe::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']]),
    ]);
    config(['oppam.sms.driver' => 'msg91', 'oppam.captcha.driver' => 'turnstile']);

    $page = Livewire::test(Integrations::class)
        ->call('test', 'razorpay')->call('test', 'msg91')->call('test', 'turnstile')->call('test', 'storage')->call('test', 'mail');

    expect($page->get('results'))
        ->razorpay->state->toBe('info')
        ->msg91->state->toBe('ok')
        ->turnstile->state->toBe('ok')
        ->storage->state->toBe('ok')
        ->mail->state->toBe('ok');
    expect(Storage::disk('public')->allFiles())->toBe([]);   // the probe file is deleted
    Http::assertSent(fn ($r) => str_starts_with($r->url(), Msg91Probe::BALANCE_URL));
    expect(AuditLog::query()->where('action', 'settings.integration_checked')->count())->toBe(5)
        ->and(AuditLog::query()->where('action', 'settings.integration_checked')->pluck('after')->toJson())
        ->not->toContain('msg91-secret-auth-key-123456')->not->toContain('0x4AAAAAAAsecretKEYdoNotShow');
});

it('A15 integrations: a refused key is reported as an error, not a crash', function (): void {
    Http::fake([
        Msg91Probe::BALANCE_URL.'*' => Http::response('Authentication failure', 200),
        TurnstileProbe::VERIFY_URL => Http::response(['success' => false, 'error-codes' => ['invalid-input-secret']]),
    ]);
    config(['oppam.sms.driver' => 'msg91', 'oppam.captcha.driver' => 'turnstile']);

    expect(app(RunIntegrationCheck::class)->handle($this->admin, 'msg91')->state)->toBe('error')
        ->and(app(RunIntegrationCheck::class)->handle($this->admin, 'turnstile')->state)->toBe('error');
});

it('A15 integrations security: settings.view can look but not test (403); unknown integration 404; past settings.integration_checks_per_hour refused', function (): void {
    $this->actingAs(adminWithRole('read_only'), 'admin');
    Livewire::test(Integrations::class)->assertOk()->assertDontSeeHtml('dusk="probe-razorpay"')
        ->call('test', 'razorpay')->assertForbidden();

    $this->actingAs($this->admin, 'admin');
    Livewire::test(Integrations::class)->call('test', 'ftp')->assertNotFound();

    // The limit is an A15 setting (settings.integration_checks_per_hour): lowered to 3, it applies at once.
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle($this->admin, ['settings.integration_checks_per_hour' => 3], 'Tighter');
    $page = Livewire::test(Integrations::class);
    foreach (range(1, 3) as $_) {
        $page->call('test', 'razorpay');
    }
    $page->call('test', 'razorpay')->assertDispatched('toast', type: 'error');
    expect(AuditLog::query()->where('action', 'settings.integration_checked')->count())->toBe(3);

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(Integrations::class)->assertForbidden();
});
