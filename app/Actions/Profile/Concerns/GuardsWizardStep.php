<?php

declare(strict_types=1);

namespace App\Actions\Profile\Concerns;

use App\Domain\Profile\CompletenessCalculator;
use App\Domain\Profile\ProfileRules;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Shared front and back half of every wizard-step save (M02): the actor must be allowed to edit
 * this profile (ProfilePolicy::editWizard), the data is validated again here with the same
 * ProfileRules the form used — the Action is the server truth whoever calls it — and every
 * write recalculates profiles.completeness (R-M02-3) in the same transaction.
 */
trait GuardsWizardStep
{
    /**
     * An autosave may keep a half-filled step only while the profile is a DRAFT: a REJECTED
     * profile must stay complete (DB CHECK chk_profiles_basics_unless_draft).
     */
    private function isPartial(Profile $profile, bool $partial): bool
    {
        return $partial && $profile->status === ProfileStatus::Draft;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, list<mixed>>  $rules
     * @return array<string, mixed> the validated values
     */
    private function authorizeAndValidate(User $actor, Profile $profile, array $values, array $rules): array
    {
        Gate::forUser($actor)->authorize('editWizard', $profile);

        return Validator::make($values, $rules, [], ProfileRules::attributes())->validate();
    }

    /**
     * Locked fields (R-M02-1) must keep their stored value; a changed one is refused with a
     * message under the field rather than silently ignored.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $locked
     *
     * @throws ValidationException
     */
    private function assertUnchanged(Profile $profile, array $values, array $locked): void
    {
        $changed = [];

        foreach ($locked as $field) {
            if (self::comparable($profile->getAttribute($field)) !== self::comparable($values[$field] ?? null)) {
                $changed[$field] = __('This can no longer be changed here. Please contact support to correct it.');
            }
        }

        if ($changed !== []) {
            throw ValidationException::withMessages($changed);
        }
    }

    /** Run the step's writes and refresh the completeness score, atomically. */
    private function persist(Profile $profile, Closure $write): void
    {
        DB::transaction(function () use ($profile, $write): void {
            $write();

            $profile->unsetRelations();
            $profile->forceFill(['completeness' => app(CompletenessCalculator::class)->percent($profile)])->save();
        });
    }

    private static function comparable(mixed $value): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d'),
            $value === null => '',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
