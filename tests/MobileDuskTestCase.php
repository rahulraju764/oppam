<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;

/**
 * Phone-width browser tests (tests/Browser/Mobile). Uses Chrome DEVICE EMULATION, not a window
 * size: headless Chrome on Windows clamps windows to 512px, so a "360px" window is really 512
 * (docs/template-notes.md "Verification harness").
 */
abstract class MobileDuskTestCase extends DuskTestCase
{
    protected function configureOptions(ChromeOptions $options): void
    {
        $options->setExperimentalOption('mobileEmulation', [
            'deviceMetrics' => ['width' => 375, 'height' => 812, 'pixelRatio' => 1, 'touch' => true, 'mobile' => true],
        ]);
    }
}
