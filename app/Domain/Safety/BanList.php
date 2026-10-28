<?php

declare(strict_types=1);

namespace App\Domain\Safety;

use App\Enums\BannedIdentifierType;
use App\Enums\SettingKey;
use App\Models\BannedIdentifier;
use App\Support\Facades\Settings;
use Illuminate\Support\Str;

/**
 * The ban blocklist (PRD A10 "ban (email/phone/device/pHash blocklist)") — THE place that writes
 * and checks it. Email, phone and device values are stored only as HMAC-SHA256 (keyed by the app
 * key, like DocumentNumber), so the list holds no readable contact details; a photo's perceptual
 * hash is stored as is and matched within `safety.phash_ban_distance` bits.
 */
final class BanList
{
    public function hash(BannedIdentifierType $type, string $value): string
    {
        return hash_hmac('sha256', $type->value.'|'.self::normalise($type, $value), 'ban-list|'.(string) config('app.key'));
    }

    public function isBanned(BannedIdentifierType $type, ?string $value): bool
    {
        if ($value === null || trim($value) === '') {
            return false;
        }

        if ($type === BannedIdentifierType::Phash) {
            return $this->isBannedPhoto($value);
        }

        return BannedIdentifier::query()->where('type', $type->value)->where('value', $this->hash($type, $value))->exists();
    }

    /**
     * Which of these values are banned — one query for a whole list (bulk import, P7.6). Not for
     * perceptual photo hashes (those match by distance: isBannedPhoto()).
     *
     * @param  list<string>  $values
     * @return list<string> the banned ones, as given
     */
    public function bannedAmong(BannedIdentifierType $type, array $values): array
    {
        $byHash = [];
        foreach ($values as $value) {
            $byHash[$this->hash($type, $value)] = $value;
        }

        $banned = [];
        foreach (array_chunk(array_keys($byHash), 500) as $chunk) {
            foreach (BannedIdentifier::query()->where('type', $type->value)->whereIn('value', $chunk)->pluck('value') as $hash) {
                $banned[] = $byHash[(string) $hash];
            }
        }

        return $banned;
    }

    /** A photo within the ban distance of a banned photo's perceptual hash (64-bit Hamming distance in the database). */
    public function isBannedPhoto(?string $phash): bool
    {
        if ($phash === null || preg_match('/^[0-9a-f]{16}$/i', $phash) !== 1) {
            return false;
        }

        return BannedIdentifier::query()->where('type', BannedIdentifierType::Phash->value)
            ->whereRaw('BIT_COUNT(CAST(CONV(value, 16, 10) AS UNSIGNED) ^ CAST(CONV(?, 16, 10) AS UNSIGNED)) <= ?', [strtolower($phash), Settings::int(SettingKey::SafetyPhashBanDistance)])
            ->exists();
    }

    /**
     * Put one identifier on the list (a repeat changes nothing).
     *
     * @return bool whether it was added now
     */
    public function add(BannedIdentifierType $type, string $value, ?string $profileId, ?string $caseId, ?string $adminId): bool
    {
        if (trim($value) === '') {
            return false;
        }

        $stored = $type === BannedIdentifierType::Phash ? strtolower($value) : $this->hash($type, $value);

        return BannedIdentifier::query()->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'type' => $type->value,
            'value' => $stored,
            'profile_id' => $profileId,
            'abuse_case_id' => $caseId,
            'banned_by_admin_id' => $adminId,
            'created_at' => now(),
            'updated_at' => now(),
        ]) === 1;
    }

    private static function normalise(BannedIdentifierType $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            BannedIdentifierType::Email => Str::lower($value),
            BannedIdentifierType::Phone => (string) preg_replace('/[^0-9+]/', '', $value),
            default => $value,
        };
    }
}
