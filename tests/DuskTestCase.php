<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Browser tests (Laravel Dusk) run against the app served by `composer dev`
 * (APP_URL, http://localhost:8000) — start it first. See CLAUDE.md "Commands".
 *
 * ChromeDriver listens on DUSK_DRIVER_PORT (default 9515). Windows sometimes reserves a port range
 * that covers 9515 (`netsh int ipv4 show excludedportrange protocol=tcp`): set another free port.
 */
abstract class DuskTestCase extends BaseTestCase
{
    #[BeforeClass]
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port='.self::driverPort()]);
        }
    }

    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            '--window-size=1440,900',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), fn (Collection $items): Collection => $items->merge([
            '--disable-gpu',
            '--headless=new',
        ]))->all());

        $this->configureOptions($options);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:'.self::driverPort(),
            DesiredCapabilities::chrome()->setCapability(ChromeOptions::CAPABILITY, $options)
        );
    }

    private static function driverPort(): int
    {
        return (int) ($_ENV['DUSK_DRIVER_PORT'] ?? env('DUSK_DRIVER_PORT') ?? 9515);
    }

    protected function configureOptions(ChromeOptions $options): void
    {
        //
    }
}
