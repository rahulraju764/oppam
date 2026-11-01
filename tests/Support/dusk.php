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
                expect($browser->script('return window.innerWidth;')[0])->toBe($width);
                expect($browser->script('return document.scrollingElement.scrollWidth <= document.documentElement.clientWidth;')[0])
                    ->toBeTrue("{$url} scrolls sideways at {$width} px");
            }
        } finally {
            $driver->quit();
        }
    }
}
