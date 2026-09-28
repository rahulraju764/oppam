<?php

declare(strict_types=1);

use App\Data\Profile\MemberChromeData;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;

/*
| P0.2 — layouts converted from the template includes (PRD §6.1, docs/template-notes.md).
| Test-only routes on the app domain render the layouts with controlled content.
*/

function layoutRoute(string $uri, string $name, string $blade, array $data = []): void
{
    Route::middleware('web')
        ->domain(config('oppam.app_domain'))
        ->get($uri, fn () => Blade::render($blade, $data))
        ->name($name);

    Route::getRoutes()->refreshNameLookups();
}

function demoMember(string $name = 'Sally Roberts'): MemberChromeData
{
    return new MemberChromeData(name: $name, code: 'OPM10001', planLabel: 'Free', photoUrl: '/images/home/profile2.webp', unreadNotifications: 3);
}

beforeEach(function (): void {
    config(['oppam.indexable' => false]);
});

it('renders the public chrome with the accessibility landmarks the template requires', function (): void {
    layoutRoute('/_layout/public', 'test.public', '<x-layouts::public><h1>Hello</h1></x-layouts::public>');

    $html = $this->get('/_layout/public')->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<main id="main" tabindex="-1">')
        ->and(strpos($html, 'class="skip-link"'))->toBeLessThan(strpos($html, '<header>'))
        ->and($html)->toContain('id="preloader"')
        ->and($html)->toContain('navbar-public')
        ->and($html)->toContain('<meta name="csrf-token"')
        ->and($html)->not->toContain('mobile-footer');   // tab bar is member-only
});

it('marks every page noindex while the site is not indexable', function (): void {
    layoutRoute('/_layout/public', 'test.public', '<x-layouts::public><h1>Hi</h1></x-layouts::public>');

    $this->get('/_layout/public')
        ->assertSee('<meta name="robots" content="noindex, nofollow, noarchive, nosnippet, noimageindex">', false);
});

it('lets public pages be indexed but never member pages once the site is indexable', function (): void {
    config(['oppam.indexable' => true]);
    layoutRoute('/_layout/public', 'test.public', '<x-layouts::public><h1>Hi</h1></x-layouts::public>');
    layoutRoute('/_layout/member', 'test.member', '<x-layouts::member :member="$m"><h1>Hi</h1></x-layouts::member>', ['m' => demoMember()]);

    $this->get('/_layout/public')->assertSee('<meta name="robots" content="index, follow">', false);
    $this->get('/_layout/member')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('builds canonical and og:url as absolute URLs on the app domain', function (): void {
    layoutRoute('/_layout/public', 'test.public', '<x-layouts::public><h1>Hi</h1></x-layouts::public>');

    $url = url('/_layout/public');

    expect(parse_url($url, PHP_URL_HOST))->toBe(config('oppam.app_domain'));

    $this->get('/_layout/public')
        ->assertSee('<link rel="canonical" href="'.$url.'">', false)
        ->assertSee('<meta property="og:url" content="'.$url.'">', false);
});

it('renders the member chrome and escapes member data (stored XSS)', function (): void {
    layoutRoute('/_layout/member', 'test.member', '<x-layouts::member :member="$m"><h1>Hi</h1></x-layouts::member>', [
        'm' => demoMember('<script>alert(1)</script>'),
    ]);

    $this->get('/_layout/member')
        ->assertOk()
        ->assertSee('navbar-member', false)
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee('<script>alert(1)</script>', false);
});

it('never links to a page that is not built yet', function (): void {
    // login, register and every member.* route arrive in P1.x.
    layoutRoute('/_layout/member', 'test.member', '<x-layouts::member :member="$m"><h1>Hi</h1></x-layouts::member>', ['m' => demoMember()]);

    $html = $this->get('/_layout/member')->getContent();

    expect($html)->not->toContain('href="#"')
        ->and($html)->not->toContain('/login')
        ->and($html)->not->toContain('mobile-footer')      // no tab routes yet → no empty bar
        ->and($html)->not->toContain('profile-logout');   // logout is a POST form once the route exists
});

it('renders the page-nav bar from config/pagenav.php by route name', function (): void {
    config(['pagenav' => [
        'test.step' => ['title' => 'Step Two', 'back' => ['home', 'Home'], 'prev' => ['home', 'Start'], 'next' => ['member.not-built', 'Later']],
    ]]);
    layoutRoute('/_layout/step', 'test.step', '<x-layouts::public><h1>Hi</h1></x-layouts::public>');

    $this->get('/_layout/step')
        ->assertSee('<p class="page-nav-title">Step Two</p>', false)
        ->assertSee('data-page-back', false)
        ->assertSee('aria-label="Previous: Start"', false)
        ->assertDontSee('Next: Later');   // unbuilt sibling: omitted, never rendered disabled
});

it('renders no page-nav bar for a route that is not in the map', function (): void {
    layoutRoute('/_layout/public', 'test.public', '<x-layouts::public><h1>Hi</h1></x-layouts::public>');

    $this->get('/_layout/public')->assertDontSee('class="page-nav"', false);
});

it('lets a page override the page-nav title and siblings at runtime', function (): void {
    config(['pagenav' => ['test.step' => ['title' => 'Mapped', 'next' => ['home', 'Mapped next']]]]);
    layoutRoute('/_layout/step', 'test.step', <<<'BLADE'
        <x-layouts::public :page-nav="['title' => 'Meera Nair', 'prev' => ['/profile/OPM1', 'Anna'], 'next' => null]"><h1>Hi</h1></x-layouts::public>
        BLADE);

    $this->get('/_layout/step')
        ->assertSee('<p class="page-nav-title">Meera Nair</p>', false)
        ->assertSee('aria-label="Previous: Anna"', false)
        ->assertDontSee('Mapped next');
});

it('finds page-nav entries for dotted route names', function (): void {
    config(['pagenav' => ['member.matches' => ['title' => 'My Matches']]]);
    layoutRoute('/_layout/matches', 'member.matches', '<x-layouts::member :member="$m"><h1>Hi</h1></x-layouts::member>', ['m' => demoMember()]);

    $this->get('/_layout/matches')->assertSee('<p class="page-nav-title">My Matches</p>', false);
});

it('does not register the styleguide outside the local environment', function (): void {
    expect(Route::has('styleguide'))->toBeFalse();

    $this->get('/styleguide')->assertNotFound();
});

it('keeps member pages noindex even when the page passes its own indexable SEO data', function (): void {
    config(['oppam.indexable' => true]);
    layoutRoute('/_layout/member', 'test.member', <<<'BLADE'
        <x-layouts::member :member="$m" :seo="new \App\Data\Content\SeoData(title: 'Anna Thomas')"><h1>Hi</h1></x-layouts::member>
        BLADE, ['m' => demoMember()]);

    $this->get('/_layout/member')
        ->assertSee('<title>Anna Thomas</title>', false)
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('renders the member tab bar and its sheets once their routes exist', function (): void {
    layoutRoute('/_tab/dashboard', 'member.dashboard', '<x-layouts::member :member="$m"><h1>Home</h1></x-layouts::member>', ['m' => demoMember()]);
    layoutRoute('/_tab/matches', 'member.matches', '<x-layouts::member :member="$m"><h1>Matches</h1></x-layouts::member>', ['m' => demoMember()]);
    layoutRoute('/_tab/interests', 'member.interests', '<x-layouts::member :member="$m"><h1>Interests</h1></x-layouts::member>', ['m' => demoMember()]);

    $html = $this->get('/_tab/matches')->assertOk()->getContent();

    expect($html)->toContain('<nav class="mobile-footer d-lg-none" aria-label="Primary">')
        ->and($html)->toContain('aria-controls="matches-sheet"')
        ->and($html)->toContain('id="matches-sheet"')
        ->and($html)->toContain('id="interests-sheet"')
        // The current section is lit in the tab bar, and the sheet marks the current page.
        ->and($html)->toMatch('/class="tab-sheet-toggle is-current"[^>]*aria-controls="matches-sheet"/')
        ->and($html)->toMatch('/class="tab-sheet-link active"\s+aria-current="page"/');
});

it('omits footer widgets whose links are not built yet', function (): void {
    layoutRoute('/_layout/member', 'test.member', '<x-layouts::member :member="$m"><h1>Hi</h1></x-layouts::member>', ['m' => demoMember()]);

    $this->get('/_layout/member')
        ->assertDontSee('<h3>Explore</h3>', false)
        ->assertDontSee('Help &amp; Support', false);
});
