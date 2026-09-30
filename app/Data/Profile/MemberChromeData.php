<?php

declare(strict_types=1);

namespace App\Data\Profile;

use App\Models\User;
use App\Services\Entitlements\EntitlementService;

/**
 * What the member header, drawer and tab bar show about the signed-in member (template
 * header.php "hardcoded demo data"). Display fields only — it is rendered into every member
 * page. Built from the authenticated profile by current(); the layouts call it when a page
 * doesn't pass one. The photo becomes the member's own in P1.4; the bell count goes live in P3.2.
 */
final readonly class MemberChromeData
{
    /** Neutral silhouette until the member's approved primary photo exists (P1.4). */
    public const PLACEHOLDER_PHOTO = 'images/matches/profile.webp';

    public function __construct(
        public string $name,
        public string $code,
        public string $planLabel,
        public string $photoUrl,
        public bool $isPremium = false,
        public int $unreadNotifications = 0,
    ) {}

    /** The signed-in member's chrome, or null for guests and broker logins (no profile). */
    public static function current(): ?self
    {
        $user = auth('web')->user();
        $profile = $user instanceof User ? $user->profile : null;

        if ($profile === null) {
            return null;
        }

        return new self(
            name: $profile->fullName(),
            code: $profile->code,
            planLabel: app(EntitlementService::class)->plan($profile)->name,
            photoUrl: asset(self::PLACEHOLDER_PHOTO),
            isPremium: $profile->is_premium,
        );
    }
}
