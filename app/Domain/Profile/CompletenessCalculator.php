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
        $done = $this->done($profile);

        return array_sum(array_map(
            fn (string $part): int => $done[$part] ? self::WEIGHTS[$part] : 0,
            array_keys(self::WEIGHTS),
        ));
    }

    /**
     * The parts still missing, with what each adds and the wizard step that fills it — for
     * "add X to reach 100 %" on My Profile (M03).
     *
     * @return list<array{part: string, label: string, points: int, step: WizardStep}>
     */
    public function missing(Profile $profile): array
    {
        $labels = [
            'basic' => [__('basic details'), WizardStep::Basic],
            'career' => [__('education & career'), WizardStep::Career],
            'family' => [__('family details'), WizardStep::Family],
            'preferences' => [__('partner preferences'), WizardStep::Preferences],
            'contact' => [__('contact details'), WizardStep::Contact],
            'photo' => [__('a photo'), WizardStep::Photos],
            'about' => [__('about me'), WizardStep::Photos],
        ];

        $missing = [];
        foreach ($this->done($profile) as $part => $isDone) {
            if (! $isDone) {
                $missing[] = ['part' => $part, 'label' => $labels[$part][0], 'points' => self::WEIGHTS[$part], 'step' => $labels[$part][1]];
            }
        }

        return $missing;
    }

    /** @return array<string, bool> part => done */
    private function done(Profile $profile): array
    {
        $progress = new WizardProgress($profile);

        return [
            'basic' => $progress->isComplete(WizardStep::Basic),
            'career' => $progress->isComplete(WizardStep::Career),
            'family' => $progress->isComplete(WizardStep::Family),
            'preferences' => $progress->isComplete(WizardStep::Preferences),
            'contact' => $progress->isComplete(WizardStep::Contact),
            'photo' => $progress->hasPhoto(),
            'about' => $progress->hasAbout(),
        ];
    }
}
