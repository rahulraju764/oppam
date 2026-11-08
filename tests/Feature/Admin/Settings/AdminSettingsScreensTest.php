<?php

declare(strict_types=1);

use App\Enums\Flag;
use App\Enums\SettingKey;
use App\Livewire\Admin\Settings\BusinessRules;
use App\Livewire\Admin\Settings\EntitlementsMatrix;
use App\Livewire\Admin\Settings\FeatureFlags;
use App\Livewire\Admin\Settings\Integrations;
use App\Livewire\Admin\Settings\SiteSettings;
use App\Models\AuditLog;
use App\Support\Facades\Feature;
use App\Support\Facades\Settings;
use Livewire\Livewire;

beforeEach(function (): void {
    seedAdminRoles();
    $this->admin = adminWithRole('super_admin');
    $this->actingAs($this->admin, 'admin');
});

it('P8.4: site settings screen mounts and updates contact details with audit log', function (): void {
    Livewire::withoutLazyLoading()
        ->test(SiteSettings::class)
        ->assertOk()
        ->assertSee('Site Settings')
        ->assertSee('Contact Details')
        ->set('supportEmail', 'care@oppam.in')
        ->set('phoneDisplay', '0484 - 2345 - 6789')
        ->set('phoneDial', '048423456789')
        ->set('reason', 'Updated customer support contact line')
        ->call('save')
        ->assertHasNoErrors();

    expect(Settings::string(SettingKey::SiteSupportEmail))->toBe('care@oppam.in')
        ->and(Settings::string(SettingKey::SitePhoneDial))->toBe('048423456789');

    expect(AuditLog::query()->where('action', 'settings.updated')->where('subject_label', 'site.support_email')->exists())->toBeTrue();
});

it('P8.4: business rules screen changes a rule (interest expiry days) without deploy', function (): void {
    // Current default is 30 days
    expect(Settings::int(SettingKey::InterestExpiryDays))->toBe(30);

    $component = Livewire::withoutLazyLoading()
        ->test(BusinessRules::class)
        ->assertOk()
        ->assertSee('Business Rules')
        ->assertSee('Interest Expiry')
        ->call('openEdit', SettingKey::InterestExpiryDays->value)
        ->assertSet('editingKey', 'interest.expiry_days')
        ->set('editingValue', 45)
        ->set('reason', 'Extended trial interest window to 45 days')
        ->call('save')
        ->assertHasNoErrors();

    // Verify change took effect immediately in the runtime cache
    expect(Settings::int(SettingKey::InterestExpiryDays))->toBe(45);

    // Verify audit log
    $log = AuditLog::query()->where('action', 'settings.updated')->where('subject_label', 'interest.expiry_days')->first();
    expect($log)->not->toBeNull()
        ->and($log->after)->toBe(['interest.expiry_days' => 45])
        ->and($log->reason)->toBe('Extended trial interest window to 45 days');
});

it('P8.4: feature flags screen toggles platform flags with audit reason', function (): void {
    // Likes default to false (or initial state)
    $initial = Feature::active(Flag::LikesEnabled);

    Livewire::withoutLazyLoading()
        ->test(FeatureFlags::class)
        ->assertOk()
        ->assertSee('Feature Flags')
        ->assertSee('likes.enabled')
        ->call('promptToggle', Flag::LikesEnabled->value, ! $initial)
        ->assertSet('selectedFlag', 'likes.enabled')
        ->assertSet('targetState', ! $initial)
        ->set('reason', 'Turn on likes for testing')
        ->call('confirmToggle')
        ->assertHasNoErrors();

    expect(Feature::active(Flag::LikesEnabled))->toBe(! $initial);

    expect(AuditLog::query()->where('action', 'feature_flags.toggled')->where('subject_label', 'likes.enabled')->exists())->toBeTrue();
});

it('P8.4: entitlements matrix screen previews member-facing comparison table', function (): void {
    Livewire::withoutLazyLoading()
        ->test(EntitlementsMatrix::class)
        ->assertOk()
        ->assertSee('Entitlements Matrix')
        ->assertSee('Manage Plans & Pricing (A06)')
        ->assertSee('Feature / Entitlement');
});

it('P8.4: integrations screen displays third-party status and runs test diagnostic calls', function (): void {
    $component = Livewire::withoutLazyLoading()
        ->test(Integrations::class)
        ->assertOk()
        ->assertSee('Third-Party Integrations')
        ->assertSee('Razorpay')
        ->assertSee('MSG91 (SMS)')
        ->assertSee('Email Service')
        ->assertSee('Storage & S3')
        ->assertSee('Real-time Broadcast')
        ->assertSee('Cloudflare Turnstile');

    // Run test actions
    $component->call('testRazorpay');
    expect($component->get('testResults.razorpay'))->not->toBeNull();

    $component->call('testSms');
    expect($component->get('testResults.sms'))->not->toBeNull();

    $component->call('testStorage');
    expect($component->get('testResults.storage.status'))->toBe('success');

    $component->call('testBroadcast');
    expect($component->get('testResults.broadcast'))->not->toBeNull();

    $component->call('testTurnstile');
    expect($component->get('testResults.turnstile'))->not->toBeNull();
});

it('P8.4: enforces settings.view and settings.edit authorization', function (): void {
    // Admin without settings.view is refused
    $readOnlyNoSettings = adminWithRole('moderator'); // moderator has moderation.view etc., not settings.view
    signInAdmin($this, $readOnlyNoSettings);

    $this->get(adminUrl('/settings/site'))->assertForbidden();
    $this->get(adminUrl('/settings/rules'))->assertForbidden();
    $this->get(adminUrl('/settings/features'))->assertForbidden();
    $this->get(adminUrl('/settings/entitlements'))->assertForbidden();
    $this->get(adminUrl('/settings/integrations'))->assertForbidden();
});
