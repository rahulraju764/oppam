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
    }
}
