<?php

declare(strict_types=1);

namespace App\Enums;

/** The six profile-wizard steps (M02, template profile-creation.php … profile-photos.php). */
enum WizardStep: int
{
    case Basic = 1;
    case Career = 2;
    case Family = 3;
    case Preferences = 4;
    case Contact = 5;
    case Photos = 6;

    public function title(): string
    {
        return match ($this) {
            self::Basic => __('Profile Creation'),
            self::Career => __('Education Details'),
            self::Family => __('Family Details'),
            self::Preferences => __('Partner Preference'),
            self::Contact => __('Contact Details'),
            self::Photos => __('Profile Photos'),
        };
    }

    public function subtitle(): string
    {
        return match ($this) {
            self::Basic => __('Basic information'),
            self::Career => __('Academic information'),
            self::Family => __('Family background'),
            self::Preferences => __('Expected life partner'),
            self::Contact => __('Add Contact Details'),
            self::Photos => __('Upload your pictures'),
        };
    }

    public function next(): ?self
    {
        return self::tryFrom($this->value + 1);
    }

    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }
}
