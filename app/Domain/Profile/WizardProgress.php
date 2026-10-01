<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\WizardStep;
use App\Models\Profile;

/**
 * Which wizard steps a profile has completed (M02) — read from the saved data itself, so an
 * autosaved half-step never counts as done. Guards step order (template TODO "a user can
 * deep-link straight to any step"): a step can be opened only when every earlier step is done.
 * Steps 4–6 are completed by the P1.3 / P1.4 forms.
 */
final class WizardProgress
{
    public function __construct(private readonly Profile $profile) {}

    public function isComplete(WizardStep $step): bool
    {
        return match ($step) {
            WizardStep::Basic => $this->filled($this->profile, ['first_name', 'last_name', 'dob', 'height_cm', 'marital_status', 'religion_id', 'mother_tongue_id']),
            WizardStep::Career => $this->filled($this->profile->educationCareer, ['education_id', 'employer_type', 'occupation_id', 'income_band_id', 'current_country_id', 'permanent_country_id']),
            WizardStep::Family => $this->filled($this->profile->familyDetail, ['father_name', 'mother_name', 'family_status_option_id']),
            WizardStep::Preferences, WizardStep::Contact, WizardStep::Photos => false,
        };
    }

    /** The first step that still needs input (the furthest a member may open). */
    public function firstIncomplete(): WizardStep
    {
        foreach (WizardStep::cases() as $step) {
            if (! $this->isComplete($step)) {
                return $step;
            }
        }

        return WizardStep::Photos;
    }

    public function canOpen(WizardStep $step): bool
    {
        return $step->value <= $this->firstIncomplete()->value;
    }

    /** 0–100: completed steps out of six, for the progress bar. */
    public function percent(): int
    {
        $done = count(array_filter(WizardStep::cases(), fn (WizardStep $step): bool => $this->isComplete($step)));

        return (int) round($done / count(WizardStep::cases()) * 100);
    }

    /** @param  list<string>  $attributes */
    private function filled(?object $model, array $attributes): bool
    {
        if ($model === null) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if ($model->{$attribute} === null || $model->{$attribute} === '') {
                return false;
            }
        }

        return true;
    }
}
