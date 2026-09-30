<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Enums\CreatedFor;
use App\Livewire\Concerns\SubmitsRegistration;
use App\Livewire\Forms\RegistrationForm;
use App\ValueObjects\PhoneNumber;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The home-page hero registration form (template index.php, M01 "Quick-register"). Same rules
 * and Action as /register; this one also asks for the date of birth.
 */
final class QuickRegister extends Component
{
    use SubmitsRegistration;

    public RegistrationForm $form;

    public function mount(): void
    {
        $this->form->withDob = true;
    }

    public function render(): View
    {
        return view('livewire.public.quick-register', [
            'createdForOptions' => CreatedFor::options(),
            'countryOptions' => PhoneNumber::countryOptions(),
        ]);
    }
}
