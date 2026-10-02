<?php

declare(strict_types=1);

namespace App\Domain\Moderation;

use App\Domain\Profile\ProfileRules;
use App\Models\ModerationItem;

/**
 * Old-next-to-new rows for a PROFILE_EDIT item (R-M02-4), with the text pre-flags of the new
 * text. Used by the edited-fields queue and the escalations list, so whoever decides an edit
 * always sees both texts. Expects profile.familyDetail and profile.partnerPreference loaded.
 */
final class ProfileEditDiff
{
    public function __construct(private readonly ModerationFlags $flags) {}

    /** @return list<array{label: string, old: string|null, new: string, flags: list<string>}> */
    public function rows(ModerationItem $item): array
    {
        $labels = ProfileRules::attributes();
        $profile = $item->profile;
        $current = [
            'profiles.first_name' => $profile?->first_name,
            'profiles.last_name' => $profile?->last_name,
            'profiles.about' => $profile?->about,
            'family_details.about_family' => $profile?->familyDetail?->getAttribute('about_family'),
            'partner_preferences.about_partner' => $profile?->partnerPreference?->about_partner,
        ];
        $rows = [];

        foreach ((array) $item->fields as $key => $new) {
            $label = $labels[substr((string) $key, (int) strpos((string) $key, '.') + 1)] ?? (string) $key;
            $text = is_string($new) ? $new : '';
            $rows[] = [
                'label' => $label,
                'old' => is_string($current[$key] ?? null) ? $current[$key] : null,
                'new' => $text,
                'flags' => array_map(fn ($flag): string => $flag->message, $this->flags->forTexts([$label => $text])),
            ];
        }

        return $rows;
    }
}
