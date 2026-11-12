<?php

declare(strict_types=1);

use App\Actions\Admin\Settings\UploadSiteLogo;
use App\Data\Content\SeoData;
use App\Enums\SettingKey;
use App\Livewire\Admin\Settings\SiteSettings;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Support\Facades\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| P8.4 — A15 site settings (owner 2026-11-12: name, logo, social links and SEO defaults editable):
| one save with one reason, only changed fields audited, applied to the public pages at once;
| the logo is re-encoded pixels only; the maintenance page shows the admin's message.
*/

beforeEach(function (): void {
    seedMasters();
    seedAdminRoles();
    Cache::flush();
    $this->actingAs(adminWithRole('super_admin'), 'admin');
});

/** A real PNG of the given size, made with GD. */
function pngUpload(int $width = 400, int $height = 120, string $name = 'logo.png'): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 224, 35, 73));
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent($name, $png);
}

it('A15 site settings: one save writes and audits only the fields that changed; the site name, social link and SEO default reach the public pages at once', function (): void {
    Livewire::test(SiteSettings::class)->assertOk()
        ->assertSet('form.site__name', config('oppam.site.name'))
        ->set('form.site__name', 'Kalyanam Matrimony')
        ->set('form.social__instagram', 'https://instagram.com/kalyanam')
        ->set('form.seo__default_title', 'Kalyanam | Kerala matrimony')
        ->set('reason', 'Rebrand')
        ->call('save')->assertHasNoErrors()->assertDispatched('toast');

    expect(AuditLog::query()->where('action', 'settings.updated')->pluck('subject_label')->sort()->values()->all())
        ->toBe(['seo.default_title', 'site.name', 'social.instagram'])
        ->and(AuditLog::query()->where('action', 'settings.updated')->pluck('reason')->unique()->all())->toBe(['Rebrand']);

    $this->get('/')->assertOk()
        ->assertSee('Kalyanam Matrimony')
        ->assertSee('https://instagram.com/kalyanam', false);
    expect((new SeoData)->pageTitle())->toBe('Kalyanam | Kerala matrimony')
        ->and((new SeoData(title: 'Own title'))->pageTitle())->toBe('Own title');
});

it('A15 site settings: an invalid field refuses the whole save (nothing written) with the error on that field', function (string $field, string $value): void {
    Livewire::test(SiteSettings::class)
        ->set('form.site__name', 'Changed name')
        ->set('form.'.$field, $value)->set('reason', 'Try')
        ->call('save')->assertHasErrors(['form.'.$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'a javascript: link' => ['social__facebook', 'javascript:alert(1)'],
    'an http (not https) link' => ['social__youtube', 'http://youtube.com/x'],
    'an empty site name' => ['site__name', ''],
    'a bad support email' => ['site__support_email', 'support-at-oppam'],
]);

it('A15 site settings: the name is escaped wherever it is shown', function (): void {
    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle(adminWithRole(), ['site.name' => '<script>alert(1)</script>'], 'XSS check');

    $this->get('/')->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
});

it('A15 logo: a PNG is re-encoded to WebP under a random name, the old upload is deleted, "remove" brings back the bundled logo — all audited', function (): void {
    Storage::fake('public');
    $admin = adminWithRole();

    $first = app(UploadSiteLogo::class)->handle($admin, pngUpload(), 'New brand');
    expect($first)->toMatch('#^site/logo-[A-Za-z0-9]{24}\.webp$#')
        ->and(substr((string) Storage::disk('public')->get($first), 8, 4))->toBe('WEBP')
        ->and(Settings::string(SettingKey::SiteLogoPath))->toBe($first);

    $second = app(UploadSiteLogo::class)->handle($admin, pngUpload(300, 100), 'Tweak');
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
    $this->get('/')->assertSee(Storage::disk('public')->url($second), false);

    app(UploadSiteLogo::class)->remove($admin, 'Back to the old one');
    Storage::disk('public')->assertMissing($second);
    expect(Settings::string(SettingKey::SiteLogoPath))->toBe('')
        ->and(AuditLog::query()->where('action', 'settings.updated')->where('subject_label', 'site.logo_path')->count())->toBe(3);
});

it('A15 logo security: SVG, a script renamed .png, too big, too small or no reason → refused, nothing left on the disk', function (Closure $file, string $reason): void {
    Storage::fake('public');

    expect(fn () => app(UploadSiteLogo::class)->handle(adminWithRole(), $file(), $reason))->toThrow(ValidationException::class);
    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Settings::string(SettingKey::SiteLogoPath))->toBe('');
})->with([
    'SVG' => [fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'Valid'],
    'PHP renamed to .png' => [fn () => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'), 'Valid'],
    'over 512 KB' => [fn () => UploadedFile::fake()->image('logo.png', 400, 120)->size(600), 'Valid'],
    'too small' => [fn () => pngUpload(50, 20), 'Valid'],
    'no reason' => [fn () => pngUpload(), ''],
]);

it('A15 site settings security: settings.view sees the form read-only (save → 403); no permission → 403', function (): void {
    $this->actingAs(adminWithRole('read_only'), 'admin');
    Livewire::test(SiteSettings::class)->assertOk()->assertDontSeeHtml('dusk="site-save"')
        ->set('form.site__name', 'Hijack')->set('reason', 'x')->call('save')->assertForbidden();
    expect(fn () => app(UploadSiteLogo::class)->handle(adminWithRole('read_only'), pngUpload(), 'x'))
        ->toThrow(Illuminate\Auth\Access\AuthorizationException::class);

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(SiteSettings::class)->assertForbidden();
    expect(Setting::query()->count())->toBe(0);
});

it('A15 maintenance: the 503 page shows the admin\'s message, else the built-in text', function (): void {
    expect(view('errors.503')->render())->toContain('down for scheduled maintenance');

    app(App\Actions\Admin\Settings\UpdateSettings::class)->handle(adminWithRole(), ['site.maintenance_message' => 'Back at 6 pm after the Onam upgrade.'], 'Upgrade');
    expect(view('errors.503')->render())->toContain('Back at 6 pm after the Onam upgrade.');
});

it('A15 entitlement matrix: previews the member-facing comparison and links to A06 plans; settings.view needed', function (): void {
    Livewire::withoutLazyLoading()->test(App\Livewire\Admin\Settings\EntitlementsMatrix::class)->assertOk()
        ->assertSee('Entitlements Matrix')->assertSee('Manage Plans & Pricing (A06)')->assertSee('Feature / Entitlement');

    $this->actingAs(adminWithRole('moderator'), 'admin');
    Livewire::test(App\Livewire\Admin\Settings\EntitlementsMatrix::class)->assertForbidden();
});
