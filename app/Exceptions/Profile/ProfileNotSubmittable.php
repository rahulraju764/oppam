<?php

declare(strict_types=1);

namespace App\Exceptions\Profile;

use App\Enums\WizardStep;
use RuntimeException;

/**
 * The profile can't be submitted for review (R-M02-2). The message is safe to show the member;
 * $step is the first step still needing input when the profile is incomplete.
 */
final class ProfileNotSubmittable extends RuntimeException
{
    public function __construct(string $message, public readonly ?WizardStep $step = null)
    {
        parent::__construct($message);
    }

    public static function incomplete(WizardStep $step): self
    {
        return new self(__('Please complete “:step” before submitting.', ['step' => $step->title()]), $step);
    }

    public static function needsPhoto(): self
    {
        return new self(__('Please add at least one photo of yourself before submitting.'), WizardStep::Photos);
    }

    public static function alreadySubmitted(): self
    {
        return new self(__('Your profile has already been submitted for review.'));
    }
}
