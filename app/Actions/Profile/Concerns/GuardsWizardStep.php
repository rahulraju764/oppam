<?php

declare(strict_types=1);

namespace App\Actions\Profile\Concerns;

use App\Domain\Profile\ProfileRules;
use App\Enums\ProfileStatus;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Shared front half of every wizard-step save (M02): the actor must be allowed to edit this
 * profile (ProfilePolicy::editWizard), and the data is validated again here with the same
 * ProfileRules the form used — the Action is the server truth whoever calls it.
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
}
