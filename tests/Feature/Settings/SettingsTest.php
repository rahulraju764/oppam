<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\ToggleFeatureFlag;
use App\Actions\Admin\Settings\UpdateSetting;
use App\Enums\Flag;
use App\Enums\Gender;
use App\Enums\SettingKey;
use App\Models\AuditLog;
use App\Models\FeatureFlag;
use App\Models\Setting;
use App\Rules\MinimumMarriageAge;
use App\Services\Settings\FeatureFlags;
use App\Services\Settings\SettingsRepository;
use App\Support\Facades\Feature;
use App\Support\Facades\Settings;
use Database\Seeders\SettingsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/*
| P0.6 — admin-editable settings and feature flags (PRD A15): typed, cached, validated, audited.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

function freshSettings(): SettingsRepository
{
    app()->forgetScopedInstances();

    return app(SettingsRepository::class);
}

it('falls back to the PRD default when a setting has no row', function (): void {
    expect(Settings::int(SettingKey::InterestExpiryDays))->toBe(30)
        ->and(Settings::int(SettingKey::InterestResendCooldownDays))->toBe(90)
        ->and(Settings::int(SettingKey::MinAgeFemale))->toBe(18)
        ->and(Settings::int(SettingKey::MinAgeMale))->toBe(21)
        ->and(Settings::string(SettingKey::SiteSupportEmail))->toBe('support@oppam.in');
});

it('returns stored values typed by their key', function (): void {
    Setting::factory()->keyed(SettingKey::InterestExpiryDays, '45')->create();
    Setting::factory()->keyed(SettingKey::SeoIndexable, 1)->create();

    expect(freshSettings()->get(SettingKey::InterestExpiryDays))->toBe(45)
        ->and(freshSettings()->get(SettingKey::SeoIndexable))->toBeTrue();
});

it('reads all settings with one query and caches them across requests', function (): void {
    freshSettings()->int(SettingKey::DailyMatchCount);   // warm the cache

    DB::enableQueryLog();
    freshSettings()->int(SettingKey::DailyMatchCount);
    freshSettings()->int(SettingKey::OtpTtlMinutes);

    expect(DB::getQueryLog())->toBe([]);
});

it('updates a setting, flushes the cache and audits before/after (A15)', function (): void {
    $admin = adminWithRole('super_admin');
    freshSettings()->int(SettingKey::InterestExpiryDays);   // cached default

    app(UpdateSetting::class)->handle($admin, SettingKey::InterestExpiryDays, '14', 'Shorter trial');

    expect(freshSettings()->int(SettingKey::InterestExpiryDays))->toBe(14)
        ->and(Setting::query()->find('interest.expiry_days')->updated_by_id)->toBe($admin->id);

    $log = AuditLog::query()->where('action', 'settings.updated')->sole();
    expect($log->before)->toBe(['interest.expiry_days' => 30])
        ->and($log->after)->toBe(['interest.expiry_days' => 14])
        ->and($log->subject_label)->toBe('interest.expiry_days')
        ->and($log->reason)->toBe('Shorter trial')
        ->and($log->actor_id)->toBe($admin->id);
});

it('rejects invalid values and writes nothing', function (SettingKey $key, mixed $value): void {
    $admin = adminWithRole('super_admin');

    expect(fn () => app(UpdateSetting::class)->handle($admin, $key, $value, 'Test'))->toThrow(ValidationException::class)
        ->and(Setting::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(0);
})->with([
    'female age below the legal 18' => [SettingKey::MinAgeFemale, 17],
    'male age below the legal 21' => [SettingKey::MinAgeMale, 20],
    'not a number' => [SettingKey::InterestExpiryDays, 'thirty'],
    'not an email' => [SettingKey::SiteSupportEmail, 'support-at-oppam'],
    'phone with letters' => [SettingKey::SitePhoneDial, '0895ABC'],
    'empty required text' => [SettingKey::SiteHours, ''],
]);

it('refuses settings changes from admins without settings.edit', function (string $role): void {
    $admin = adminWithRole($role);

    expect(fn () => app(UpdateSetting::class)->handle($admin, SettingKey::InterestExpiryDays, 14, 'Test'))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(ToggleFeatureFlag::class)->handle($admin, Flag::LikesEnabled, true, 'Launch'))
        ->toThrow(AuthorizationException::class)
        ->and(Setting::query()->count() + FeatureFlag::query()->count())->toBe(0);
})->with(['moderator', 'support', 'finance', 'content_editor']);

it('refuses settings changes from a suspended super admin', function (): void {
    $admin = adminWithRole('super_admin');
    $admin->forceFill(['status' => App\Enums\AdminStatus::Suspended])->save();

    expect(fn () => app(UpdateSetting::class)->handle($admin, SettingKey::InterestExpiryDays, 14, 'Test'))
        ->toThrow(AuthorizationException::class);
});

it('requires a reason for every settings or flag change', function (): void {
    $admin = adminWithRole('super_admin');

    expect(fn () => app(UpdateSetting::class)->handle($admin, SettingKey::InterestExpiryDays, 14, ''))->toThrow(ValidationException::class)
        ->and(fn () => app(ToggleFeatureFlag::class)->handle($admin, Flag::LikesEnabled, true, ' '))->toThrow(ValidationException::class)
        ->and(Setting::query()->count() + FeatureFlag::query()->count())->toBe(0);
});

it('audits the stored value as before, not a stale cached one', function (): void {
    $admin = adminWithRole('super_admin');
    freshSettings()->int(SettingKey::DailyMatchCount);   // caches the default 10
    Setting::factory()->keyed(SettingKey::DailyMatchCount, 12)->create();   // changed behind the cache

    app(UpdateSetting::class)->handle($admin, SettingKey::DailyMatchCount, 15, 'Tune');

    expect(AuditLog::query()->where('action', 'settings.updated')->sole()->before)->toBe(['matching.daily_match_count' => 12]);
});

it('keeps every feature flag off until it is switched on', function (): void {
    foreach (Flag::cases() as $flag) {
        expect(Feature::active($flag))->toBeFalse();
    }
});

it('toggles a flag, flushes the cache and audits it (A15)', function (): void {
    $admin = adminWithRole('super_admin');
    expect(Feature::active(Flag::LikesEnabled))->toBeFalse();   // cached off

    app(ToggleFeatureFlag::class)->handle($admin, Flag::LikesEnabled, true, 'P2.2 shipped');

    app()->forgetScopedInstances();
    expect(app(FeatureFlags::class)->active(Flag::LikesEnabled))->toBeTrue()
        ->and(app(FeatureFlags::class)->active(Flag::ChatImages))->toBeFalse();

    $log = AuditLog::query()->where('action', 'feature_flags.toggled')->sole();
    expect($log->before)->toBe(['likes.enabled' => false])
        ->and($log->after)->toBe(['likes.enabled' => true])
        ->and($log->reason)->toBe('P2.2 shipped');
});

it('seeds every setting except seo.indexable, and every flag, without overwriting an admin change', function (): void {
    Setting::factory()->keyed(SettingKey::InterestExpiryDays, 14)->create();
    (new FeatureFlag)->forceFill(['key' => Flag::LikesEnabled->value, 'is_enabled' => true])->save();

    $this->seed(SettingsSeeder::class);
    $this->seed(SettingsSeeder::class);

    expect(Setting::query()->count())->toBe(count(SettingKey::cases()) - 1)   // all but seo.indexable
        ->and(Setting::query()->find(SettingKey::SeoIndexable->value))->toBeNull()
        ->and(FeatureFlag::query()->count())->toBe(count(Flag::cases()))
        ->and(freshSettings()->int(SettingKey::InterestExpiryDays))->toBe(14)
        ->and(freshSettings()->int(SettingKey::InterestResendCooldownDays))->toBe(90)
        ->and(app(FeatureFlags::class)->active(Flag::LikesEnabled))->toBeTrue()
        ->and(FeatureFlag::query()->where('is_enabled', true)->count())->toBe(1);
});

it('defines valid defaults for every setting key', function (SettingKey $key): void {
    expect(Validator::make(['value' => $key->default()], ['value' => $key->rules()])->passes())->toBeTrue();
})->with(fn (): array => array_filter(SettingKey::cases(), fn (SettingKey $key): bool => $key !== SettingKey::SiteMaintenanceMessage));

/*
| MinimumMarriageAge (M02 step 1): 18 for women, 21 for men, on the IST date; editable in A15.
*/

function marriageAgeFails(Gender|string|null $gender, string $dob): bool
{
    return Validator::make(['dob' => $dob], ['dob' => [new MinimumMarriageAge($gender)]])->fails();
}

it('enforces 18 for women and 21 for men on the IST date (M02)', function (): void {
    // 2026-09-28 00:30 IST = 2026-09-27 19:00 UTC: in UTC it is still the 27th.
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-09-27 19:00:00', 'UTC'));

    expect(marriageAgeFails(Gender::Female, '2008-09-28'))->toBeFalse()   // 18 today (IST)
        ->and(marriageAgeFails(Gender::Female, '2008-09-29'))->toBeTrue()
        ->and(marriageAgeFails('MALE', '2005-09-28'))->toBeFalse()        // 21 today (IST)
        ->and(marriageAgeFails('MALE', '2005-09-29'))->toBeTrue()
        ->and(marriageAgeFails(null, '2020-01-01'))->toBeFalse()          // gender reports its own error
        ->and(marriageAgeFails(Gender::Female, 'not-a-date'))->toBeTrue()
        ->and(marriageAgeFails(Gender::Female, '-30 years'))->toBeTrue()        // no relative strings
        ->and(marriageAgeFails(Gender::Female, '1990-02-30'))->toBeTrue();      // no overflowing dates
});

it('follows an admin change to the minimum age', function (): void {
    $this->travelTo(Carbon\CarbonImmutable::parse('2026-09-28 06:00:00', 'UTC'));
    Setting::factory()->keyed(SettingKey::MinAgeFemale, 20)->create();
    app()->forgetScopedInstances();

    expect(marriageAgeFails(Gender::Female, '2007-01-01'))->toBeTrue()
        ->and(marriageAgeFails(Gender::Female, '2006-01-01'))->toBeFalse();
});
