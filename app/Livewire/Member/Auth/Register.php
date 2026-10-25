<?php

declare(strict_types=1);

namespace App\Livewire\Member\Auth;

use App\Data\Content\SeoData;
use App\Domain\Broker\BrokerCodeLookup;
use App\Enums\CreatedFor;
use App\Livewire\Concerns\SubmitsRegistration;
use App\Livewire\Forms\RegistrationForm;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * /register (template register.php, M01). Continues to /verify-otp. The bureau referral code is
 * pre-filled from the `?ref=` first-touch cookie (§11A B.5, R-M13-9).
 */
final class Register extends Component
{
    use SubmitsRegistration;

    public RegistrationForm $form;

    public function mount(): void
    {
        $cookie = request()->cookie((string) config('oppam.auth.referral_cookie'));
        $this->form->referralCode = BrokerCodeLookup::normalise(is_string($cookie) ? $cookie : null) ?? '';
    }

    public function render(BrokerCodeLookup $codes): View
    {
        return view('livewire.member.auth.register', [
            'createdForOptions' => CreatedFor::options(),
            'countryOptions' => PhoneNumber::countryOptions(),
            'referralHint' => trim($this->form->referralCode) === '' ? null : $codes->hint($this->form->referralCode),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Register Free | Oppam Matrimony Kerala',
            description: 'Create your free Oppam Matrimony profile and meet verified Kerala brides and grooms.',
            keywords: 'Kerala Matrimony Registration, Oppam Matrimony, Malayali Matrimony, Register Free',
            ogTitle: 'Register free on Oppam Matrimony',
        )]);
    }
}
