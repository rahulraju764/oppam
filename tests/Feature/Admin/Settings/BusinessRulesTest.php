<?php

declare(strict_types=1);

use App\Actions\Engagement\SendInterest;
use App\Enums\SettingKey;
use App\Livewire\Admin\Settings\BusinessRules;
use App\Models\AuditLog;
use App\Models\Interest;
use App\Models\Setting;
use App\Support\Facades\Settings;
use Database\Seeders\PlansSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
| P8.4 — A15 business rules: every rule from the SettingKey enum, typed + validated + audited,
| applied without a deploy. Done when: changing a business rule (interest expiry days) takes
| effect without deploy (the next interest uses it).
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    Notification::fake();
    $this->actingAs(adminWithRole('super_admin'), 'admin');
});

it('A15 Done when: changing interest expiry days on the screen applies to the NEXT interest, without a deploy; an earlier one keeps its date', function (): void {
    $this->seed(PlansSeeder::class);
    $this->travelTo(now()->startOfMinute());
    $groom = groom();
    app(SendInterest::class)->handle($groom, bride()->profile, 'T01');
    $before = Interest::query()->sole();

    Livewire::test(BusinessRules::class)
        ->call('edit', 'interest.expiry_days')->assertSet('value', '30')
        ->set('value', '7')->set('reason', 'Shorter trial for launch')
        ->call('save')->assertHasNoErrors()->assertDispatched('toast');

    app(SendInterest::class)->handle($groom, bride('+919800000097')->profile, 'T01');

    $after = Interest::query()->whereKeyNot($before->id)->sole();
    expect($after->expires_at->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($before->refresh()->expires_at->equalTo(now()->addDays(30)))->toBeTrue();
    expect(AuditLog::query()->where('action', 'settings.updated')->sole())
        ->before->toBe(['interest.expiry_days' => 30])->after->toBe(['interest.expiry_days' => 7])->reason->toBe('Shorter trial for launch');
});

it('A15: the photo limits are business rules too — a lowered size limit refuses the next upload', function (): void {
    fakeMediaDisks();
    $user = memberThroughStep(5);
    Livewire::test(BusinessRules::class)->call('edit', 'photos.max_upload_mb')->set('value', '1')->set('reason', 'Save storage')->call('save')->assertHasNoErrors();

    expect(fn () => app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), UploadedFile::fake()->image('big.jpg', 800, 800)->size(2048)))
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(App\Domain\Profile\PhotoLimits::maxKilobytes())->toBe(1024)
        ->and(App\Domain\Profile\PhotoLimits::maxPhotos())->toBe(10);   // the main photo + 9 (unchanged)
});

it('A15: every business rule in the enum is on the screen with its section and unit — the campaign rules included; site keys are not', function (): void {
    $page = Livewire::test(BusinessRules::class)->assertOk()->assertSeeHtml('wire:loading');

    foreach (SettingKey::cases() as $key) {
        $key->isSite()
            ? $page->assertDontSeeHtml('dusk="rule-edit-'.$key->value.'"')
            : $page->assertSeeHtml('dusk="rule-edit-'.$key->value.'"');
    }
    $page->assertSee('Notifications & campaigns')->assertSee(SettingKey::CampaignsApprovalThreshold->label())
        ->set('q', 'otp')->assertSee(SettingKey::OtpTtlMinutes->label())->assertDontSee(SettingKey::CampaignsApprovalThreshold->label())
        ->set('q', 'zzz-nothing')->assertSee('No rule matches');
});

it('A15: an invalid value or no reason shows the error on the field and writes nothing', function (string $key, string $value, string $reason, string $error): void {
    Livewire::test(BusinessRules::class)->call('edit', $key)->set('value', $value)->set('reason', $reason)
        ->call('save')->assertHasErrors([$error]);

    expect(Setting::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'settings.updated')->count())->toBe(0);
})->with([
    'below the legal minimum age' => ['profile.min_age_female', '17', 'Fix', 'value'],
    'not a number' => ['interest.expiry_days', 'thirty', 'Fix', 'value'],
    'above the allowed maximum' => ['campaigns.max_per_minute', '999999', 'Fix', 'value'],
    'bad GSTIN' => ['billing.seller_gstin', 'NOT-A-GSTIN', 'Fix', 'value'],
    'no reason' => ['interest.expiry_days', '20', '', 'reason'],
]);

it('A15 security: settings.view sees the rules but can\'t change one (403); no permission → 403; unknown, site or tampered key refused', function (): void {
    $this->actingAs(adminWithRole('read_only'), 'admin');
    Livewire::test(BusinessRules::class)->assertOk()->assertDontSeeHtml('dusk="rule-edit-interest.expiry_days"')
        ->call('edit', 'interest.expiry_days')->assertForbidden();

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(BusinessRules::class)->assertForbidden();

    $this->actingAs(adminWithRole('super_admin'), 'admin');
    Livewire::test(BusinessRules::class)->call('edit', 'no.such_key')->assertNotFound();
    Livewire::test(BusinessRules::class)->call('edit', 'site.name')->assertNotFound();   // edited on Site settings
    Livewire::test(BusinessRules::class)->call('save')->assertNotFound();               // nothing opened
    expect(fn () => Livewire::test(BusinessRules::class)->call('edit', 'interest.expiry_days')->set('editing', 'profile.min_age_female'))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect(Settings::int(SettingKey::InterestExpiryDays))->toBe(30);
});

it('A15 UpdateSettings: one save is all-or-nothing, and only changed keys are written and audited', function (): void {
    $admin = adminWithRole('super_admin');
    $update = app(App\Actions\Admin\Settings\UpdateSettings::class);

    expect(fn () => $update->handle($admin, ['interest.expiry_days' => 20, 'profile.min_age_male' => 18], 'Mixed'))   // 18 < legal 21
        ->toThrow(Illuminate\Validation\ValidationException::class);
    expect(Setting::query()->count())->toBe(0);

    $changed = $update->handle($admin, ['interest.expiry_days' => 20, 'interest.resend_cooldown_days' => 90], 'One change');   // 90 = unchanged default
    expect($changed)->toBe(['interest.expiry_days'])
        ->and(AuditLog::query()->where('action', 'settings.updated')->pluck('subject_label')->all())->toBe(['interest.expiry_days']);

    expect(fn () => $update->handle($admin, ['made.up' => 1], 'x'))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('A15: photos.max_additional applies to the next upload — lowered to 1, a third photo is refused', function (): void {
    fakeMediaDisks();
    Illuminate\Support\Facades\Bus::fake([Spatie\MediaLibrary\Conversions\Jobs\PerformConversionsJob::class]);
    $user = memberThroughStep(5);
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle(adminWithRole(), ['photos.max_additional' => 1], 'Fewer photos');
    $upload = fn (string $name) => app(App\Actions\Profile\Photos\UploadProfilePhoto::class)->handle($user, $user->profile()->firstOrFail(), UploadedFile::fake()->image($name, 500, 500));

    $upload('one.jpg');
    $upload('two.jpg');
    expect(fn () => $upload('three.jpg'))->toThrow(Illuminate\Validation\ValidationException::class);
    expect(App\Models\Media::query()->count())->toBe(2);
});
