<?php

declare(strict_types=1);

use App\Enums\SettingKey;
use App\Models\Setting;
use Database\Seeders\PlansSeeder;
use Illuminate\Support\Facades\Route;

/*
| P0.3 — the public pages converted from the template (PRD §6.2, M12) and the legacy *.php
| redirects (PRD §6.1).
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);   // pricing cards come from the plans table (P0.4)
});

dataset('public pages', [
    'home' => ['/', 'Oppam Matrimony | Trusted Kerala Matrimony'],
    'about' => ['/about', 'About Us | Oppam Matrimony'],
    'branches' => ['/branches', 'Our Branches | Oppam Matrimony'],
    'success stories' => ['/success-stories', 'Success Stories | Oppam Matrimony'],
    'plans' => ['/plans', 'Membership Packages & FAQ | Oppam Matrimony'],
    'contact' => ['/contact', 'Contact Us | Oppam Matrimony'],
    'privacy' => ['/privacy', 'Privacy Policy | Oppam Matrimony'],
    'terms' => ['/terms', 'Terms of Use | Oppam Matrimony'],
]);

it('serves every public page with exactly one h1 and its own title', function (string $uri, string $title): void {
    $html = $this->get($uri)->assertOk()->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<title>'.e($title).'</title>')
        ->and($html)->toContain('<main id="main" tabindex="-1">');
})->with('public pages');

it('never serves the public pages on the admin domain', function (string $uri): void {
    $response = $this->get('http://'.config('oppam.admin_domain').$uri);

    // "/" there is the admin dashboard (guests → admin login, P0.5); every other public path 404s.
    $uri === '/'
        ? $response->assertRedirect(route('admin.login'))
        : $response->assertNotFound();
})->with('public pages');

it('returns a real 404 status and the designed not-found page for an unknown URL', function (): void {
    $this->get('/no-such-page')
        ->assertNotFound()
        ->assertSee('We couldn’t find that page')
        ->assertSee('<meta name="robots" content="noindex, nofollow', false);
});

it('301s the template FAQ page to the FAQ on the plans page', function (): void {
    $this->get('/faq')->assertStatus(301)->assertRedirect('/plans#faq');
});

it('301s every legacy template URL to its new route', function (string $legacy, string $target): void {
    $this->get($legacy)->assertStatus(301)->assertRedirect($target);
})->with([
    ['/index.php', '/'],
    ['/about.php', '/about'],
    ['/package.php', '/plans'],
    ['/faq.php', '/plans#faq'],
    ['/contact.php', '/contact'],
    ['/privacy.php', '/privacy'],
    ['/terms.php', '/terms'],
    ['/success-stories.php', '/success-stories'],
    ['/branches.php', '/branches'],
    ['/profile-creation.php', '/onboarding/1'],
    ['/single-profile.php', '/profiles'],
    ['/index', '/'],
]);

it('returns 404 for a *.php URL the template never had', function (): void {
    $this->get('/wp-login.php')->assertNotFound();
});

it('renders the hero register form as the live QuickRegister component (P1.1 replaced the disabled placeholder)', function (): void {
    expect(Route::has('register'))->toBeTrue();

    $html = $this->get('/')->getContent();

    expect($html)->toContain('wire:submit="register"')                              // posts through Livewire, not a plain form
        ->and($html)->toContain('id="reg-created-for"')                               // "Profile for" (PRD M01)
        ->and($html)->not->toContain('Online registration opens soon')
        ->and($html)->not->toMatch('/<button type="submit"\s+disabled>/');
});

it('keeps the contact form disabled until its handler exists (P8.1)', function (): void {
    $this->get('/contact')
        ->assertSee('The online form opens soon')
        ->assertSee('<button type="submit" disabled>', false);
});

it('shows the three plans with server-formatted rupee prices and no purchase link before checkout exists', function (): void {
    $this->get('/plans')
        ->assertSeeInOrder(['plan-name">Silver', 'plan-name">Gold', 'plan-name">Diamond'], false)
        ->assertSee('Most Popular')
        ->assertSee('<span>1,999</span>', false)
        ->assertSee('Billed as ₹23,988 per year')
        ->assertDontSee('Purchase Now');
});

it('links the home plans teaser to the plans page', function (): void {
    $this->get('/')->assertSee('href="'.route('plans').'" class="btn-purchase"', false);
});

it('links each success-story card to its full story on the same page', function (): void {
    $this->get('/success-stories')
        ->assertSee('href="#story-allen-riya"', false)
        ->assertSee('id="story-allen-riya"', false);
});

it('shows the contact details from the admin-editable settings on both the page and the footer (A15)', function (): void {
    Setting::factory()->keyed(SettingKey::SiteSupportEmail, 'help@oppam.test')->create();

    $html = $this->get('/contact')->getContent();

    expect(substr_count($html, 'mailto:help@oppam.test'))->toBe(2);   // contact aside + footer
});

it('marks the legal pages as unreviewed placeholders until counsel signs them off', function (string $uri): void {
    $this->get($uri)->assertSee('class="legal-fill"', false);
})->with(['/privacy', '/terms']);

it('never renders a dead href="#" link on a public page', function (string $uri): void {
    expect($this->get($uri)->getContent())->not->toContain('href="#"');
})->with('public pages');

it('renders every error page with its real status, noindex and a way home', function (int $status, string $title): void {
    Route::middleware('web')->domain(config('oppam.app_domain'))->get('/_error/'.$status, fn () => abort($status));

    $this->get('/_error/'.$status)
        ->assertStatus($status)
        ->assertSee($title)
        ->assertSee('<meta name="robots" content="noindex, nofollow', false)
        ->assertSee('href="'.route('home').'" class="view-btn error-btn"', false);
})->with([
    [403, 'You don’t have access to this page'],
    [404, 'We couldn’t find that page'],
    [419, 'This page has expired'],
    [429, 'Too many attempts'],
    [500, 'Something went wrong on our side'],
    [503, 'We’ll be right back'],
]);

it('keeps the 500 and 503 pages free of links that need the rest of the site', function (int $status): void {
    Route::middleware('web')->domain(config('oppam.app_domain'))->get('/_error/'.$status, fn () => abort($status));

    $this->get('/_error/'.$status)->assertDontSee('Or try one of these');
})->with([500, 503]);
