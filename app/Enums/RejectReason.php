<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\Settings\SettingsRepository;

/**
 * Why a moderator rejected a profile, photo or edit (A04; owner-approved list 2026-10-01). Each
 * maps to the member-facing message (memberMessage); the moderator's own note is added below it.
 */
enum RejectReason: string
{
    case Incomplete = 'INCOMPLETE';
    case Misleading = 'MISLEADING';
    case Inappropriate = 'INAPPROPRIATE';
    case ContactInText = 'CONTACT_IN_TEXT';
    case Duplicate = 'DUPLICATE';
    case Underage = 'UNDERAGE';
    case Other = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::Incomplete => __('Incomplete or unclear details'),
            self::Misleading => __('Fake or misleading information'),
            self::Inappropriate => __('Inappropriate photo or text'),
            self::ContactInText => __('Contact details in text'),
            self::Duplicate => __('Duplicate profile'),
            self::Underage => __('Underage'),
            self::Other => __('Other'),
        };
    }

    /** What the member reads (moderator's note follows). */
    public function memberMessage(): string
    {
        return match ($this) {
            self::Incomplete => __('Some details are missing or unclear. Please complete them and submit again.'),
            self::Misleading => __('Some details look incorrect. Please make sure everything is true and accurate.'),
            self::Inappropriate => __('A photo or text doesn’t follow our community guidelines. Please replace it.'),
            self::ContactInText => __('Please remove phone numbers, emails or social handles from your texts and photos — members share contacts through Oppam.'),
            self::Duplicate => __('This looks like a second profile for the same person. Each person may have one profile.'),
            self::Underage => __('Members must be at least the legal marriage age (:female for brides, :male for grooms).', [
                'female' => app(SettingsRepository::class)->int(SettingKey::MinAgeFemale),
                'male' => app(SettingsRepository::class)->int(SettingKey::MinAgeMale),
            ]),
            self::Other => __('Your profile needs a few changes before it can go live.'),
        };
    }

    /** @return array<string, string> value => label */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])->all();
    }
}
