<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Data\Auth\RegistrationData;
use App\Enums\CreatedFor;
use App\Enums\Gender;
use App\Rules\MinimumMarriageAge;
use App\Rules\ValidMobileNumber;
use App\Support\Auth\MemberPassword;
use App\ValueObjects\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Form;

/**
 * The registration fields shared by the home hero (Public\QuickRegister) and /register
 * (Member\Auth\Register) — one rule set for both (M01). The hero also asks for the date of birth
 * ($withDob, fixed by the component). Gender is taken from "Profile for" when it implies one
 * (Son/Brother → male, Daughter/Sister → female) whatever the browser sends.
 * Uniqueness of mobile and email is decided by RegisterMember, not here (an unverified holder
 * releases the number).
 */
final class RegistrationForm extends Form
{
    #[Locked]
    public bool $withDob = false;

    public string $createdFor = '';

    public string $name = '';

    public string $gender = '';

    public string $dobDay = '';

    public string $dobMonth = '';

    public string $dobYear = '';

    /** Composed from the three DOB boxes just before validation (Y-m-d), or '' when not asked. */
    public string $dob = '';

    public string $countryCode = PhoneNumber::DEFAULT_COUNTRY;

    public string $mobile = '';

    public string $email = '';

    public string $password = '';

    public bool $terms = false;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'createdFor' => ['required', Rule::enum(CreatedFor::class)],
            'name' => ['required', 'string', 'min:2', 'max:120', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'-]*$/u"],
            'gender' => ['required', Rule::enum(Gender::class)],
            'dob' => $this->withDob
                ? ['required', new MinimumMarriageAge(Gender::tryFrom($this->gender))]
                : ['nullable'],
            'countryCode' => ['required', Rule::in(PhoneNumber::countryCodes())],
            'mobile' => ['required', 'string', 'max:20', new ValidMobileNumber($this->countryCode)],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string', 'max:72', MemberPassword::rule()],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'createdFor' => __('profile for'),
            'dob' => __('date of birth'),
            'countryCode' => __('country code'),
            'mobile' => __('mobile number'),
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.regex' => __('Use letters only for the name.'),
            'terms.accepted' => __('Please accept the Terms of Use and Privacy Policy.'),
            'dob.required' => __('Enter your date of birth.'),
        ];
    }

    /** Called when "Profile for" changes: Son/Daughter/Brother/Sister fix the gender. */
    public function applyDerivedGender(): void
    {
        $derived = CreatedFor::tryFrom($this->createdFor)?->derivedGender();

        if ($derived !== null) {
            $this->gender = $derived->value;
        }
    }

    public function genderIsDerived(): bool
    {
        return CreatedFor::tryFrom($this->createdFor)?->derivedGender() !== null;
    }

    public function toData(): RegistrationData
    {
        $this->applyDerivedGender();
        $this->dob = $this->withDob ? $this->composeDob() : '';

        $this->validate();

        [$first, $last] = self::splitName($this->name);

        return new RegistrationData(
            createdFor: CreatedFor::from($this->createdFor),
            firstName: $first,
            lastName: $last,
            gender: Gender::from($this->gender),
            dob: $this->dob !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $this->dob) ?: null : null,
            phone: PhoneNumber::fromParts($this->countryCode, $this->mobile),
            email: trim($this->email) !== '' ? trim($this->email) : null,
            password: $this->password,
        );
    }

    /**
     * "Anjali Mary Thomas" → first "Anjali", last "Mary Thomas". Wizard step 1 (P1.2) shows both
     * fields, so the member can correct the split.
     *
     * @return array{0: string, 1: ?string}
     */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [''];

        return [mb_substr($parts[0], 0, 60), isset($parts[1]) ? mb_substr($parts[1], 0, 60) : null];
    }

    private function composeDob(): string
    {
        foreach ([$this->dobDay, $this->dobMonth, $this->dobYear] as $part) {
            if (! ctype_digit(trim($part))) {
                return trim($this->dobDay.$this->dobMonth.$this->dobYear) === '' ? '' : 'invalid';
            }
        }

        return sprintf('%04d-%02d-%02d', (int) $this->dobYear, (int) $this->dobMonth, (int) $this->dobDay);
    }
}
