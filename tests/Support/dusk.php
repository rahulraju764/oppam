<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Database\Seeders\AdminRolesSeeder;
use Laravel\Dusk\Browser;
use PragmaRX\Google2FA\Google2FA;

/*
| Dusk admin helpers (tests/Browser/Admin, tests/Browser/Mobile). They run against the LOCAL dev
| server and database: a throwaway dusk-admin@oppam.test account with a RANDOM password is created
| for each test and deleted afterwards (duskAdminCleanup, registered in tests/Pest.php). Only its
| own rate-limiter keys are cleared. Its audit rows stay (audit_logs is append-only).
| Loaded from tests/Pest.php.
*/

const DUSK_ADMIN_EMAIL = 'dusk-admin@oppam.test';

function duskAdminPassword(): string
{
    static $password = null;

    return $password ??= 'Dusk-'.Illuminate\Support\Str::password(24, symbols: false).'-1a';
}

function duskAdmin(): AdminUser
{
    (new AdminRolesSeeder)->run(app(Spatie\Permission\PermissionRegistrar::class));

    duskAdminCleanup();

    $admin = new AdminUser;
    $admin->forceFill(['name' => 'Dusk Admin', 'email' => DUSK_ADMIN_EMAIL, 'password' => duskAdminPassword()])->save();
    $admin->assignRole('super_admin');

    return $admin;
}

/** Remove the throwaway account (sessions cascade) and its rate-limiter keys. */
function duskAdminCleanup(): void
{
    $admin = AdminUser::query()->firstWhere('email', DUSK_ADMIN_EMAIL);

    Illuminate\Support\Facades\RateLimiter::clear('admin-login:email:'.sha1(DUSK_ADMIN_EMAIL));
    Illuminate\Support\Facades\RateLimiter::clear('admin-login:ip:127.0.0.1');

    if ($admin !== null) {
        Illuminate\Support\Facades\RateLimiter::clear('admin-2fa:'.$admin->id);
        $admin->syncRoles([]);
        $admin->delete();
    }
}

function adminDuskUrl(string $path): string
{
    return 'http://'.config('oppam.admin_domain').':'.parse_url(config('app.url'), PHP_URL_PORT).$path;
}

/** Sign the Dusk admin in through the real password + 2FA-enrolment screens. */
function duskSignInAdmin(Browser $browser): void
{
    duskAdmin();

    $browser->visit(adminDuskUrl('/login'))
        ->type('#f-email', DUSK_ADMIN_EMAIL)
        ->type('#f-password', duskAdminPassword())
        ->press('Continue')
        ->waitForLocation('/two-factor/setup');

    $code = app(Google2FA::class)->getCurrentOtp(str_replace(' ', '', $browser->text('.mono-key')));

    $browser->type('#f-code', $code)
        ->press('Confirm')
        ->waitForText('Save your recovery codes')
        ->press('I have saved them — continue')
        ->waitForLocation('/');
}

/**
 * Gate D: each path in a browser of its own at each width (phone emulation keeps a running browser
 * at 375 px, so it can't be resized), carrying the signed-in cookies of $signedIn.
 *
 * @param  list<string>  $urls
 */
function assertFitsWidths(Browser $signedIn, string $landing, array $urls): void
{
    $cookies = $signedIn->driver->manage()->getCookies();
    $port = (int) ($_ENV['DUSK_DRIVER_PORT'] ?? env('DUSK_DRIVER_PORT') ?? 9515);

    foreach ([320, 768, 1024, 1440] as $width) {
        $options = (new Facebook\WebDriver\Chrome\ChromeOptions)->addArguments(['--headless=new', '--disable-gpu', '--disable-smooth-scrolling']);
        $options->setExperimentalOption('mobileEmulation', [
            'deviceMetrics' => ['width' => $width, 'height' => 900, 'pixelRatio' => 1, 'touch' => $width < 1024, 'mobile' => $width < 768],
        ]);
        $driver = Facebook\WebDriver\Remote\RemoteWebDriver::create('http://localhost:'.$port,
            Facebook\WebDriver\Remote\DesiredCapabilities::chrome()->setCapability(Facebook\WebDriver\Chrome\ChromeOptions::CAPABILITY, $options));

        try {
            $browser = new Browser($driver);
            $browser->visit($landing);
            foreach ($cookies as $cookie) {
                $driver->manage()->addCookie($cookie);
            }

            foreach ($urls as $url) {
                $browser->visit($url)->waitFor('main#main');
                $body = (string) $browser->script('return document.body.innerText;')[0];
                expect((string) $driver->getCurrentURL())->toContain((string) parse_url($url, PHP_URL_PATH));   // still signed in: no redirect to sign-in
                expect($body)->not->toContain('We couldn’t find that page')->not->toContain('doesn’t include this')->not->toContain('Server Error');
                expect($browser->script('return window.innerWidth;')[0])->toBe($width, "{$url} is wider than {$width} px (mobile emulation widened the viewport)");
                expect($browser->script('return document.scrollingElement.scrollWidth <= document.documentElement.clientWidth;')[0])
                    ->toBeTrue("{$url} scrolls sideways at {$width} px");
            }
        } finally {
            $driver->quit();
        }
    }
}

/**
 * P9.3b — WCAG 2.1 A / AA check of the page as it is now (PRD §14), with axe-core from node_modules.
 * Every violation fails, whatever its impact; `$skipRules` (axe rule ids) is for a decision recorded in
 * docs/decisions.md only. Call it after the page (and any dialog being checked) has settled.
 *
 * @param  list<string>  $skipRules
 */
function assertAccessible(Browser $browser, string $page, array $skipRules = []): void
{
    if (! $browser->script('return typeof window.axe === "object";')[0]) {
        $browser->script((string) file_get_contents(base_path('node_modules/axe-core/axe.min.js')));
    }

    $browser->driver->manage()->timeouts()->setScriptTimeout(60);
    $violations = $browser->driver->executeAsyncScript(sprintf(<<<'JS'
        const done = arguments[arguments.length - 1];
        const skip = %s;
        axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'] }, resultTypes: ['violations'] })
            .then(r => done(r.violations.filter(v => ! skip.includes(v.id)).map(v =>
                `${v.id} (${v.impact}) — ${v.help} — ` + v.nodes.slice(0, 3).map(n => n.target.join(' ')).join(' | ')
                + (v.nodes.length > 3 ? ` (+${v.nodes.length - 3} more)` : ''))))
            .catch(e => done(['axe failed to run: ' + e.message]));
    JS, json_encode(array_values($skipRules), JSON_THROW_ON_ERROR)));

    expect($violations)->toBe([], "WCAG 2.1 A/AA violations on $page");
}

/**
 * P9.3a — visible <i> icons NOT drawn by Font Awesome 6 (wrong font, no glyph, or zero width): an icon
 * whose class or font didn't load is an empty box that width checks alone don't catch.
 *
 * @return list<string> "class — font / content / width" per broken icon
 */
function brokenFontAwesomeIcons(Browser $browser): array
{
    return $browser->script(<<<'JS'
        return [...document.querySelectorAll('i[class*="fa-"]')]
            .filter(i => i.offsetParent !== null)
            .map(i => {
                const before = getComputedStyle(i, '::before');
                // FA6's style IS its weight: regular / brands draw at 400, solid at 900 — a CSS rule that changes an
                // icon's weight silently swaps it to the other style's glyph (e.g. an outline icon turning filled).
                const want = i.classList.contains('fa-solid') ? '900' : '400';
                return { cls: i.className, font: before.fontFamily || getComputedStyle(i).fontFamily, content: before.content,
                    width: i.getBoundingClientRect().width, weight: String(before.fontWeight || getComputedStyle(i).fontWeight), want };
            })
            .filter(x => !/Font Awesome 6/.test(x.font) || x.content === 'none' || x.content === '""' || x.width === 0 || x.weight !== x.want)
            .map(x => `${x.cls} — ${x.font} / ${x.content} / ${x.width}px / weight ${x.weight} (want ${x.want})`);
    JS)[0];
}
