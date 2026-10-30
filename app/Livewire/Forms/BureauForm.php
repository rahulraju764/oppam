<?php

declare(strict_types=1);

namespace App\Livewire\Forms;

use App\Data\Broker\NewBureauData;
use App\Models\Masters\District;
use App\Rules\ActiveMaster;
use App\Rules\ValidMobileNumber;
use App\ValueObjects\PhoneNumber;
use Livewire\Form;

/**
 * A07 "create broker" (§11A B.12): the Owner's name, bureau name, mobile, optional email and
 * district — the same rules as /broker/apply (BrokerApplicationForm) without PAN or password,
 * which the Owner gives when they apply. The code is generated (R-A07-1), never a field.
 */
final class BureauForm extends Form
{
    public string $name = '';

    public string $bureauName = '';

    public string $mobile = '';

    public string $email = '';

    public string $districtId = '';

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', "regex:/^[\\p{L}\\p{M}][\\p{L}\\p{M} .'’-]*$/u"],
            'bureauName' => ['nullable', 'string', 'max:120', "regex:/^[\\p{L}\\p{M}0-9][\\p{L}\\p{M}0-9 .,&'’()-]*$/u"],
            'mobile' => ['required', 'string', 'max:20', new ValidMobileNumber(PhoneNumber::DEFAULT_COUNTRY)],
            'email' => ['nullable', 'string', 'email:rfc', 'max:255'],
            'districtId' => ['required', 'integer', new ActiveMaster(District::class)],
        ];
    }

    /** @return array<string, string> */
    protected function validationAttributes(): array
    {
        return [
            'bureauName' => __('bureau name'),
            'mobile' => __('mobile number'),
            'districtId' => __('district'),
        ];
    }

    public function toData(): NewBureauData
    {
        $this->validate();

        return new NewBureauData(
            name: trim($this->name),
            bureauName: trim($this->bureauName) !== '' ? trim($this->bureauName) : null,
            phone: PhoneNumber::fromParts(PhoneNumber::DEFAULT_COUNTRY, $this->mobile),
            email: trim($this->email) !== '' ? strtolower(trim($this->email)) : null,
            districtId: (int) $this->districtId,
        );
    }
}
