<?php

declare(strict_types=1);

use App\Livewire\Admin\Chat\Overview;
use App\Livewire\Admin\Dashboard;
use Database\Seeders\PlansSeeder;
use Livewire\Livewire;

/*
| P9.3b — WCAG 2.1 AA landmarks and live regions the axe runs rely on (PRD §14), checked without a browser:
| every layout has a skip link to <main id="main">, toasts are a polite live region on public pages too,
| and chart data tables are hidden on a wrapper (a hidden <table> ignored the 1px width and overflowed 320px).
*/

beforeEach(function (): void {
    $this->seed(PlansSeeder::class);
});

it('a11y: the admin sign-in page has a skip link to the main landmark (it had none)', function (): void {
    $this->get(adminUrl('/login'))->assertOk()
        ->assertSee('<a class="skip-link" href="#main">', false)
        ->assertSee('<main id="main" tabindex="-1"', false);
});

it('a11y: public pages carry the polite toast live region — a signed-in member\'s live toasts are no longer lost there', function (string $path): void {
    $this->get($path)->assertOk()
        ->assertSee('class="toast-stack" x-data="toastStack"', false)
        ->assertSee('role="status" aria-live="polite"', false);

    $this->actingAs(memberWithPhone());
    $this->get($path)->assertOk()->assertSee('class="toast-stack"', false);
})->with(['/', '/plans', '/contact']);

it('a11y: chart data tables on the A02 dashboard and the A13 chat overview are hidden on a wrapper div, never on the <table>', function (string $component): void {
    seedAdminRoles();
    test()->actingAs(adminWithRole('super_admin'), 'admin');

    Livewire::test($component)->assertOk()
        ->assertDontSeeHtml('<table class="visually-hidden">')
        ->assertSeeHtml('<div class="visually-hidden"><table>');
})->with(['A02 dashboard' => [Dashboard::class], 'A13 chat overview' => [Overview::class]]);
