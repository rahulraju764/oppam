<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Data\Broker\BrokerApplicationData;
use App\Domain\Broker\PanNumber;
use App\Models\Masters\District;
use App\Rules\ActiveMaster;
use App\Rules\ValidMobileNumber;
use App\Support\Auth\MemberPassword;
use App\ValueObjects\PhoneNumber;
use Livewire\Form;

/**
 * The /broker/apply fields (§11A B.11: name, bureau name, mobile + OTP, district, PAN) plus an
 * optional email and the login password. Indian mobiles only (a bureau has an Indian PAN).
 * Uniqueness of mobile and PAN is decided by ApplyAsBroker, without revealing either.
 */
final class BrokerApplicationForm extends Form
{
    public string $name = '';

    public string $bureauName = '';

    public string $mobile = '';

    public string $email = '';

    public string $districtId = '';

    public string $pan = '';

    public string $password = '';

    public bool $terms = false;

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’-]*$/u"],
            'bureauName' => ['nullable', 'string', 'max:120', "regex:/^[\\p{L}\\p{M}0-9][\\p{L}\\p{M}0-9 .,&'’()-]*$/u"],
            'mobile' => ['required', 'string', 'max:20', new ValidMobileNumber(PhoneNumber::DEFAULT_COUNTRY)],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'districtId' => ['required', 'integer', new ActiveMaster(District::class)],
            'pan' => ['required', 'string', 'regex:'.PanNumber::PATTERN],
            'password' => ['required', 'string', 'max:72', MemberPassword::rule()],
            'terms' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'bureauName' => __('bureau name'),
            'mobile' => __('mobile number'),
            'districtId' => __('district'),
            'pan' => __('PAN'),
        ];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [
            'name.regex' => __('Use letters only for the name.'),
            'bureauName.regex' => __('Use letters, numbers and simple punctuation for the bureau name.'),
            'pan.regex' => __('A PAN has 10 characters: 5 letters, 4 digits and a letter (e.g. ABCDE1234F).'),
            'terms.accepted' => __('Please accept the broker terms and the Privacy Policy.'),
        ];
    }

    public function toData(): BrokerApplicationData
    {
        $this->pan = PanNumber::normalise($this->pan);
        $this->validate();

        return new BrokerApplicationData(
            name: trim($this->name),
            bureauName: trim($this->bureauName) !== '' ? trim($this->bureauName) : null,
            phone: PhoneNumber::fromParts(PhoneNumber::DEFAULT_COUNTRY, $this->mobile),
            email: trim($this->email) !== '' ? strtolower(trim($this->email)) : null,
            districtId: (int) $this->districtId,
            pan: $this->pan,
            password: $this->password,
        );
    }
}
