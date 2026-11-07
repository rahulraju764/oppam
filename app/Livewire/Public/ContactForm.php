<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Support\SubmitContact;
use App\Contracts\CaptchaVerifier;
use App\Data\Support\ContactRequestData;
use App\Enums\TicketCategory;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * The /contact form (template contact.php, M12): the message goes to the A08 contact inbox through
 * SubmitContact (honeypot, validation, Turnstile, rate limit, spam tab), owner 2026-11-02 "inbox
 * first". The team can convert it into a support ticket there. A bot caught by the honeypot sees the
 * same thank-you and nothing is stored.
 */
final class ContactForm extends Component
{
    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $category = '';

    public string $message = '';

    /** Honeypot: hidden from people, filled by bots. */
    public string $website = '';

    /** The Turnstile widget's single-use token, set by resources/js/alpine/turnstile.js. */
    public string $captchaToken = '';

    public ?string $sentCode = null;

    public function mount(): void
    {
        $member = auth('web')->user();
        if ($member instanceof User) {
            $this->firstName = (string) $member->profile?->first_name;
            $this->lastName = (string) $member->profile?->last_name;
            $this->email = (string) $member->email;
        }
    }

    public function send(SubmitContact $submit): void
    {
        $member = auth('web')->user();

        try {
            $submission = $submit->handle($member instanceof User ? $member : null, new ContactRequestData(
                name: trim($this->firstName.' '.$this->lastName),
                email: $this->email,
                category: $this->category,
                message: $this->message,
                honeypot: $this->website,
                captchaToken: $this->captchaToken !== '' ? $this->captchaToken : null,
            ), (string) request()->ip());
        } catch (ValidationException $invalid) {
            $this->resetCaptcha();

            throw $invalid;
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->resetCaptcha();
            $this->addError('message', $refused->getMessage());

            return;
        }

        $this->sentCode = $submission === null ? '—' : $submission->code;
        $this->resetCaptcha();
        $this->reset('message', 'category');
    }

    public function render(CaptchaVerifier $captcha): View
    {
        return view('livewire.public.contact-form', [
            'categories' => TicketCategory::options(),
            'captchaKey' => $captcha->enabled() ? $captcha->siteKey() : null,
        ]);
    }

    /** A Turnstile token works once: every attempt needs a fresh one. */
    private function resetCaptcha(): void
    {
        $this->captchaToken = '';
        $this->dispatch('captcha-reset');
    }
}
