<?php

declare(strict_types=1);

use App\Actions\Auth\SignInMember;
use App\Enums\ProfileStatus;
use Database\Seeders\PlansSeeder;

/*
| P1.1 — the M01 pages render on the member host with the template's accessibility rules
| (one <h1>, main landmark), the right robots and the chrome links that appear once routes exist.
*/

it('renders each auth page with exactly one h1', function (string $path, string $text): void {
    $html = $this->get(memberUrl($path))->assertOk()->assertSee($text)->getContent();

    expect(substr_count($html, '<h1'))->toBe(1)
        ->and($html)->toContain('<main id="main" tabindex="-1">');
})->with([
    'login' => ['/login', 'Welcome back'],
    'register' => ['/register', 'Register free'],
    'forgot password' => ['/forgot-password', 'Forgot password?'],
]);

it('sends /verify-otp to /register when no registration is pending', function (): void {
    $this->get(memberUrl('/verify-otp'))->assertRedirect(route('register'));
});

it('never lets search engines index the code and reset screens', function (): void {
    config(['oppam.indexable' => true]);

    $this->get(memberUrl('/forgot-password'))->assertSee('noindex', false);
});

it('shows Login / Register in the public header now that the routes exist', function (): void {
    $this->get(memberUrl('/'))
        ->assertSee('href="'.route('login').'"', false)
        ->assertSee('href="'.route('register').'"', false);
});

it('shows Logout and "Log out other devices" in the member header', function (): void {
    fakeSms();
    $this->seed(PlansSeeder::class);
    $user = memberWithPhone(status: ProfileStatus::Draft);

    $this->actingAs($user, 'web')->withSession([SignInMember::EPOCH_SESSION_KEY => 0])
        ->get(memberUrl('/onboarding/1'))
        ->assertOk()
        ->assertSee('Log out other devices')
        ->assertSee('Logout');
});

it('keeps the auth pages off the admin domain', function (string $path): void {
    $this->get(adminUrl($path))->assertNotFound();
})->with(['/register', '/verify-otp', '/forgot-password']);
