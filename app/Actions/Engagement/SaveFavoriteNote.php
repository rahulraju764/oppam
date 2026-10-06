<?php

declare(strict_types=1);

namespace App\Actions\Engagement;

use App\Exceptions\Admin\ImpersonationRestricted;
use App\Models\Favorite;
use App\Models\Profile;
use App\Models\User;
use App\Services\Admin\Impersonation;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The private note on one of the member's own favourites (PRD M06, §7.2 `favorites.note`): plain
 * text up to 200 characters, empty clears it. Found by the favourite's profile code within the
 * member's OWN favourites only — any other code changes nothing.
 */
final class SaveFavoriteNote
{
    public const MAX = 200;

    public function __construct(private readonly Impersonation $impersonation) {}

    /**
     * @return bool whether a favourite of the member's was updated
     *
     * @throws ValidationException note too long (key `note`)
     * @throws ImpersonationRestricted an admin impersonating the member
     */
    public function handle(User $member, string $profileCode, ?string $note): bool
    {
        $this->impersonation->assertAllowed();

        $note = trim((string) $note);
        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:'.self::MAX]])->validate();

        $own = $member->profile;
        $targetId = preg_match('/^OPM\d{1,10}$/', $profileCode) === 1
            ? Profile::withTrashed()->where('code', $profileCode)->value('id')
            : null;

        if ($own === null || $targetId === null) {
            return false;
        }

        return Favorite::query()->where('profile_id', $own->id)->where('favorite_profile_id', $targetId)
            ->update(['note' => $note === '' ? null : $note, 'updated_at' => now()]) > 0;
    }
}
