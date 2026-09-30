<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/*
| P0.5 review Blocker — Livewire's single update endpoint must never run an admin component
| outside the admin domain (where none of the admin route middleware would apply), and the admin
| domain must never run member/public components. Real HTTP round-trips, as an attacker would.
*/

beforeEach(function (): void {
    seedAdminRoles();
});

/** The first wire:snapshot on a rendered page, as the browser would send it back. */
function snapshotFrom(string $html): string
{
    preg_match('/wire:snapshot="([^"]+)"/', $html, $match);

    return html_entity_decode($match[1] ?? '', ENT_QUOTES);
}

function livewireUpdate(string $host, string $snapshot, array $calls = []): Illuminate\Testing\TestResponse
{
    return test()->withHeaders(['X-Livewire' => 'true'])->postJson(
        'http://'.$host.app(HandleRequests::class)->getUpdateUri(),
        ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => $calls]]],
    );
}

it('runs the admin login component on the admin host', function (): void {
    $snapshot = snapshotFrom($this->get(adminUrl('/login'))->getContent());

    livewireUpdate(config('oppam.admin_domain'), $snapshot, [['method' => 'login', 'params' => [], 'metadata' => []]])
        ->assertOk();
});

it('refuses an admin component snapshot replayed on the member host (404)', function (): void {
    $snapshot = snapshotFrom($this->get(adminUrl('/login'))->getContent());
    expect($snapshot)->toContain('admin.auth.login');

    livewireUpdate(config('oppam.app_domain'), $snapshot, [['method' => 'login', 'params' => [], 'metadata' => []]])
        ->assertNotFound();
});

it('refuses a public component snapshot replayed on the admin host (404)', function (): void {
    $this->seed(Database\Seeders\PlansSeeder::class);
    $snapshot = snapshotFrom($this->get('/')->getContent());
    expect($snapshot)->toContain('public.home');

    livewireUpdate(config('oppam.admin_domain'), $snapshot)->assertNotFound();
});

it('refuses a payload without a readable component name', function (): void {
    livewireUpdate(config('oppam.admin_domain'), '{"memo":{}}')->assertNotFound();
});

it('gives a suspended or locked admin no permission at all, whatever the entry point', function (string $state): void {
    $admin = AdminUser::factory()->withTwoFactor()->{$state}()->role('super_admin')->create();

    expect($admin->can('staff.invite'))->toBeFalse()
        ->and($admin->can('roles.edit'))->toBeFalse();
})->with(['suspended', 'locked']);
