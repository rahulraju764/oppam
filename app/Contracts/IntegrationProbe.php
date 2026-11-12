<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Data\Settings\IntegrationStatusData;
use App\Models\AdminUser;

/**
 * One third-party integration on A15 → Integrations (P8.4). facts(): what is configured, from
 * config only — masked public ids, driver names; never a secret, no network. check(): a real,
 * read-only call to the provider (or, for mail, one message to the admin's own address).
 */
interface IntegrationProbe
{
    public function key(): string;

    public function label(): string;

    /** @return array<string, string> label => value, safe to show */
    public function facts(): array;

    public function check(AdminUser $admin): IntegrationStatusData;
}
