<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Profile\Concerns\GuardsWizardStep;
use App\Data\Profile\ContactDetailsData;
use App\Domain\Profile\ProfileRules;
use App\Models\ContactDetail;
use App\Models\Profile;
use App\Models\User;
use App\ValueObjects\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Wizard step 5 (M02): contact email, alternate mobile (stored as E.164), contact person,
 * convenient time and the address (country → state → district must chain). The verified
 * primary mobile (users.phone) is never changed here.
 */
final class SaveContactDetails
{
    use GuardsWizardStep;

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(User $actor, Profile $profile, ContactDetailsData $data, bool $partial = false): void
    {
        $partial = $this->isPartial($profile, $partial);
        $values = $this->authorizeAndValidate($actor, $profile, $data->toArray(), ProfileRules::contact($data->toArray(), $partial));

        $attributes = [];
        foreach (array_keys(ProfileRules::contact([], true)) as $field) {
            $value = $values[$field] ?? null;
            $attributes[$field] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        if (is_string($attributes['alternate_phone'])) {
            $attributes['alternate_phone'] = PhoneNumber::tryParse($attributes['alternate_phone'])?->e164();
        }

        if (is_string($attributes['contact_email'])) {
            $attributes['contact_email'] = mb_strtolower($attributes['contact_email']);
        }

        $this->persist($profile, function () use ($profile, $attributes): void {
            $contact = ContactDetail::query()->whereKey($profile->id)->first() ?? new ContactDetail;
            $contact->forceFill(['profile_id' => $profile->id, ...$attributes])->save();
        });
    }
}
