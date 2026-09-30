<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Layout tests must not depend on a built Vite manifest (CI doesn't run npm build).
        $this->withoutVite();

        // defer() work (e.g. OTP SMS sent after the response, P1.1) runs immediately in tests,
        // so its effects can be asserted in the same test.
        $this->withoutDefer();
    }
}
