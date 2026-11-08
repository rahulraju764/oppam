<?php

declare(strict_types=1);

namespace App\Domain\Auth;

use App\Enums\SettingKey;
use App\Enums\UserRole;
use App\Models\Profile;
use App\Models\ProfileClosure;
use App\Models\User;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Str;

/**
 * Which closed accounts may their owner bring back by signing in (M14, P8.2c — owner 2026-11-08)?
 * A member account the member closed themselves (CloseAccount wrote an unrestored ProfileClosure
 * at or after its deletion), not anonymised, still inside `members.purge_after_days`. An A03 admin
 * delete has no such closure, so it is never self-restorable. Every caller answers exactly as for
 * an unknown account until the member has proved who they are (R-M01-4).
 */
final class ClosedAccountLookup
{
    /** The closed account behind a sign-in id (mobile, email or profile code), if restorable. */
    public function forLoginId(string $identifier): ?User
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return $this->restorable(User::onlyTrashed()->where('email', Str::lower($identifier))->first());
        }

        if (preg_match('/^OPM\d{4,9}$/i', $identifier) === 1) {
            $userId = Profile::withTrashed()->where('code', Str::upper($identifier))->value('user_id');

            return is_string($userId) ? $this->restorable(User::onlyTrashed()->find($userId)) : null;
        }

        $phone = PhoneNumber::tryParse($identifier);

        return $phone !== null ? $this->forPhone($phone) : null;
    }

    public function forPhone(PhoneNumber $phone): ?User
    {
        return $this->restorable(User::onlyTrashed()->where('phone', $phone->e164())->first());
    }

    /** The account itself, if its owner may still restore it. */
    public function restorable(?User $user): ?User
    {
        return $user !== null && $this->closureFor($user) !== null ? $user : null;
    }

    /** The member's own unrestored closure behind this deletion, or null. */
    public function closureFor(User $user): ?ProfileClosure
    {
        if (! $user->trashed() || $user->role !== UserRole::Member || $user->anonymised_at !== null
            || $user->phone_verified_at === null || $user->deleted_at === null
            || $user->deleted_at->lte(now()->subDays(Settings::int(SettingKey::MembersPurgeAfterDays)))) {
            return null;
        }

        /** @var ProfileClosure|null $closure */
        $closure = ProfileClosure::query()
            ->where('user_id', $user->id)
            ->latest('created_at')
            ->first();

        if ($closure === null || $closure->restored_at !== null
            || $closure->created_at->lt($user->deleted_at->subMinute())) {
            return null;
        }

        return $closure;
    }
}
