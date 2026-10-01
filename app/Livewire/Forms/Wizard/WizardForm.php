<?php

declare(strict_types=1);

namespace App\Livewire\Forms\Wizard;

use App\Domain\Profile\ProfileRules;
use BackedEnum;
use DateTimeInterface;
use Livewire\Form;

/**
 * Base for the wizard step forms (M02). Inputs arrive as strings from the browser; these helpers
 * turn them into the typed values the step DTOs carry. Rules come from ProfileRules only.
 */
abstract class WizardForm extends Form
{
    /** @return array<string, string> friendly field names for error messages */
    protected function validationAttributes(): array
    {
        return ProfileRules::attributes();
    }

    protected static function int(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    protected static function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected static function toInput(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            default => (string) $value,
        };
    }
}
