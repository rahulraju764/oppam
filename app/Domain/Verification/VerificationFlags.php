<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Enums\SettingKey;
use App\Enums\UserStatus;
use App\Enums\VerificationFlag;
use App\Models\Profile;
use App\Models\VerificationDocument;
use App\Models\VerificationRequest;
use App\Support\Facades\Settings;

/**
 * The A05 fraud signals for a new submission, computed once when it arrives (stored on the
 * request): the same file or the same document number already used by ANOTHER profile, a name
 * that doesn't match the profile (NameMatcher), the name + date of birth of a BANNED member, and
 * rapid resubmission. Details carry other profiles' codes only. They never decide anything.
 */
final class VerificationFlags
{
    public function __construct(private readonly NameMatcher $names) {}

    /**
     * @param  list<string>  $fileHashes  sha256 of each uploaded file (as sent)
     * @return list<array{code: string, detail: string}>
     */
    public function forSubmission(Profile $profile, string $numberHash, array $fileHashes, string $nameOnDocument): array
    {
        $flags = [];

        $sameFile = VerificationDocument::query()
            ->whereIn('sha256', $fileHashes)
            ->join('verification_requests', 'verification_requests.id', '=', 'verification_documents.verification_request_id')
            ->join('profiles', 'profiles.id', '=', 'verification_requests.profile_id')
            ->where('verification_requests.profile_id', '!=', $profile->id)
            ->distinct()->limit(5)->pluck('profiles.code')->all();
        if ($sameFile !== []) {
            $flags[] = $this->flag(VerificationFlag::DuplicateDocument, __('Also uploaded by :codes', ['codes' => implode(', ', $sameFile)]));
        }

        $sameNumber = VerificationRequest::query()
            ->where('number_hash', $numberHash)
            ->where('verification_requests.profile_id', '!=', $profile->id)
            ->join('profiles', 'profiles.id', '=', 'verification_requests.profile_id')
            ->distinct()->limit(5)->pluck('profiles.code')->all();
        if ($sameNumber !== []) {
            $flags[] = $this->flag(VerificationFlag::DuplicateNumber, __('Also used by :codes', ['codes' => implode(', ', $sameNumber)]));
        }

        if (! $this->names->matches($profile->first_name, $nameOnDocument)) {
            $flags[] = $this->flag(VerificationFlag::NameMismatch, __('Profile: “:profile” · document: “:document”', ['profile' => $profile->fullName(), 'document' => $nameOnDocument]));
        }

        $banned = $this->bannedMatches($profile, $nameOnDocument);
        if ($banned !== []) {
            $flags[] = $this->flag(VerificationFlag::BannedNameMatch, __('Same name and date of birth as banned :codes', ['codes' => implode(', ', $banned)]));
        }

        $days = Settings::int(SettingKey::VerificationRapidResubmitDays);
        $recent = VerificationRequest::query()->where('profile_id', $profile->id)->where('submitted_at', '>=', now()->subDays($days))->count() + 1;
        if ($recent >= Settings::int(SettingKey::VerificationRapidResubmitCount)) {
            $flags[] = $this->flag(VerificationFlag::RapidResubmission, __(':count submissions in :days days', ['count' => $recent, 'days' => $days]));
        }

        return $flags;
    }

    /**
     * Banned members born on the same day whose profile name — or a name they put on an ID —
     * normalises to the same as the name on this document (or this profile's name).
     *
     * @return list<string> their profile codes
     */
    private function bannedMatches(Profile $profile, string $nameOnDocument): array
    {
        if ($profile->dob === null) {
            return [];
        }

        $keys = array_filter([$this->names->key($nameOnDocument), $this->names->key($profile->fullName())]);

        return Profile::withTrashed()
            ->where('profiles.id', '!=', $profile->id)
            ->whereDate('dob', $profile->dob)
            ->whereHas('user', fn ($q) => $q->withTrashed()->where('status', UserStatus::Banned))
            ->with(['verificationRequests:id,profile_id,name_on_document'])
            ->limit(50)
            ->get(['id', 'code', 'first_name', 'last_name'])
            ->filter(function (Profile $other) use ($keys): bool {
                $names = [$other->fullName(), ...$other->verificationRequests->pluck('name_on_document')->filter()->all()];

                return array_intersect($keys, array_map(fn (string $n): string => $this->names->key($n), $names)) !== [];
            })
            ->pluck('code')->values()->all();
    }

    /** @return array{code: string, detail: string} */
    private function flag(VerificationFlag $flag, string $detail): array
    {
        return ['code' => $flag->value, 'detail' => $detail];
    }
}
