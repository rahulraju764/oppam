<?php

declare(strict_types=1);

namespace App\Domain\Profile;

use App\Enums\WizardStep;
use App\Models\Media;
use App\Models\Profile;
use App\Services\Masters\Masters;

/**
 * Which wizard steps a profile has completed (M02) — read from the saved data itself, so an
 * autosaved half-step never counts as done. Guards step order (template TODO "a user can
 * deep-link straight to any step"): a step can be opened only when every earlier step is done.
 * Step 6 is complete with an about-me of ABOUT_MIN characters and at least one photo.
 */
final class WizardProgress
{
    private ?bool $hasPhoto = null;

    public function __construct(private readonly Profile $profile) {}

    public function isComplete(WizardStep $step): bool
    {
        return match ($step) {
            WizardStep::Basic => $this->filled($this->profile, ['first_name', 'last_name', 'dob', 'height_cm', 'marital_status', 'religion_id', 'mother_tongue_id']),
            WizardStep::Career => $this->filled($this->profile->educationCareer, ['education_id', 'employer_type', 'occupation_id', 'income_band_id', 'current_country_id', 'permanent_country_id']),
            WizardStep::Family => $this->filled($this->profile->familyDetail, ['father_name', 'mother_name', 'family_status_option_id']),
            WizardStep::Preferences => $this->filled($this->profile->partnerPreference, ['age_min', 'age_max', 'religion_ids']),
            WizardStep::Contact => $this->contactComplete(),
            WizardStep::Photos => $this->hasAbout() && $this->hasPhoto(),
        };
    }

    /** Every step done: the profile may be submitted for review (R-M02-2). */
    public function isReadyToSubmit(): bool
    {
        foreach (WizardStep::cases() as $step) {
            if (! $this->isComplete($step)) {
                return false;
            }
        }

        return true;
    }

    /** About me written, at least ProfileRules::ABOUT_MIN characters (completeness "about", R-M02-3). */
    public function hasAbout(): bool
    {
        return mb_strlen(trim((string) $this->profile->getAttribute('about'))) >= ProfileRules::ABOUT_MIN;
    }

    /**
     * At least one photo that isn't rejected (completeness "photo", R-M02-3; required for
     * submit, M02 step 6). A pending photo counts: moderation decides what others see.
     */
    public function hasPhoto(): bool
    {
        return $this->hasPhoto ??= Media::query()
            ->where('model_type', $this->profile->getMorphClass())
            ->where('model_id', $this->profile->getKey())
            ->where('collection_name', Profile::PHOTOS)
            ->notRejected()
            ->exists();
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

    private function contactComplete(): bool
    {
        $contact = $this->profile->contactDetail;

        if (! $this->filled($contact, ['contact_email', 'country_id', 'city'])) {
            return false;
        }

        // India (any country with states in master data) also needs state and district.
        $hasStates = app(Masters::class)->statesForCountry((int) $contact?->country_id) !== [];

        return ! $hasStates || $this->filled($contact, ['state_id', 'district_id']);
    }

    /** @param  list<string>  $attributes */
    private function filled(?object $model, array $attributes): bool
    {
        if ($model === null) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if ($model->{$attribute} === null || $model->{$attribute} === '' || $model->{$attribute} === []) {
                return false;
            }
        }

        return true;
    }
}
