<?php

declare(strict_types=1);

namespace App\Data\Profile;

/**
 * What the member header, drawer and tab bar show about the signed-in member (template
 * header.php "hardcoded demo data"). Display fields only — it is rendered into every member
 * page. Built from the authenticated profile once members exist (P1.x); the bell count
 * becomes live in P3.2.
 */
final readonly class MemberChromeData
{
    public function __construct(
        public string $name,
        public string $code,
        public string $planLabel,
        public string $photoUrl,
        public bool $isPremium = false,
        public int $unreadNotifications = 0,
    ) {}
}
