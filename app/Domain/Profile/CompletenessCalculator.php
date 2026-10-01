<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\WizardStep;
use App\Models\Profile;

/**
 * Profile completeness, 0–100 (R-M02-3): a weighted sum of the parts that are done —
 * basic 25, career 15, family 15, preferences 15, contact 10, photo 15, about 5. Stored on
 * profiles.completeness by every wizard save; profiles under 60 % rank lower in search (M04).
 * A part counts only when it is complete (WizardProgress), never pro rata.
 */
final class CompletenessCalculator
{
    public const WEIGHTS = [
        'basic' => 25,
        'career' => 15,
        'family' => 15,
        'preferences' => 15,
        'contact' => 10,
        'photo' => 15,
        'about' => 5,
    ];

    /** Below this, search ranks the profile lower (R-M02-3). */
    public const LOW_RANK_BELOW = 60;

    public function percent(Profile $profile): int
    {
        $progress = new WizardProgress($profile);

        $done = [
            'basic' => $progress->isComplete(WizardStep::Basic),
            'career' => $progress->isComplete(WizardStep::Career),
            'family' => $progress->isComplete(WizardStep::Family),
            'preferences' => $progress->isComplete(WizardStep::Preferences),
            'contact' => $progress->isComplete(WizardStep::Contact),
            'photo' => $progress->hasPhoto(),
            'about' => $progress->hasAbout(),
        ];

        return array_sum(array_map(
            fn (string $part): int => $done[$part] ? self::WEIGHTS[$part] : 0,
            array_keys(self::WEIGHTS),
        ));
    }
}
