<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test bootstrapping (oppam-testing skill)
|--------------------------------------------------------------------------
| Feature, Livewire and Broadcasting tests boot Laravel and use the `oppam_testing`
| MariaDB database (phpunit.xml). Unit tests stay framework-free and fast.
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature', 'Livewire', 'Broadcasting');

// Browser tests (php artisan dusk) — phone-width ones use device emulation.
pest()->extend(Tests\MobileDuskTestCase::class)->in('Browser/Mobile');

require_once __DIR__.'/Support/admin.php';
require_once __DIR__.'/Support/auth.php';
pest()->extend(Tests\DuskTestCase::class)->in('Browser/Admin', 'Browser/Member');
require_once __DIR__.'/Support/dusk.php';
require_once __DIR__.'/Support/dusk-member.php';
