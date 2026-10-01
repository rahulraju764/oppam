<?php

declare(strict_types=1);

namespace App\Actions\Profile\Photos;

use App\Actions\Profile\Photos\Concerns\ManagesOwnPhotos;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/** Remove the member's horoscope file (M11). Nothing to do when there is none. */
final class DeleteHoroscope
{
    use ManagesOwnPhotos;

    /** @throws AuthorizationException */
    public function handle(User $actor, Profile $profile): void
    {
        $this->authorizePhotos($actor, $profile);

        $profile->clearMediaCollection(Profile::HOROSCOPE);
    }
}
