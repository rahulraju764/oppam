<?php

declare(strict_types=1);

namespace App\Domain\Engagement;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Services\Admin\Impersonation;

/**
 * R-M06-6: favourites are private — "admins see them only in aggregate". During an admin support
 * session (impersonation) the member's bookmarks and private notes are therefore not shown at
 * all: /favorites gives only the number, and no card or button says "bookmarked". THE place that
 * answers "may this session see the member's favourites?".
 */
final class FavoritePrivacy
{
    public function __construct(private readonly Impersonation $impersonation) {}

    public function hiddenInThisSession(): bool
    {
        try {
            $this->impersonation->assertAllowed();
        } catch (ImpersonationRestricted) {
            return true;
        }

        return false;
    }
}
