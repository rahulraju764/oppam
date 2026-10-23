<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Actions\Support\OpenTicket;
use App\Data\Support\TicketRequestData;
use App\Enums\TicketCategory;
use App\Enums\TicketSource;
use App\Exceptions\Admin\ImpersonationRestricted;
use App\Exceptions\Safety\SafetyRefused;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The /contact form (template contact.php, PRD A10 "support tickets from contact form",
 * decisions 2026-10-23): opens a support ticket through OpenTicket (validated, rate-limited).
 * A filled honeypot field is treated as a bot — it sees the same thank-you and nothing is stored.
 * Turnstile and the A08 contact inbox / spam tab come with P8.1.
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

    public function send(OpenTicket $open): void
    {
        if (trim($this->website) !== '') {
            $this->sentCode = '—';

            return;
        }

        $category = TicketCategory::tryFrom($this->category);
        $member = auth('web')->user();

        try {
            $ticket = $open->handle($member instanceof User ? $member : null, new TicketRequestData(
                name: trim($this->firstName.' '.$this->lastName),
                email: $this->email,
                phone: null,
                category: $this->category,
                subject: $category?->label() ?? __('Contact form'),
                message: $this->message,
            ), TicketSource::ContactForm, (string) request()->ip());
        } catch (SafetyRefused|ImpersonationRestricted $refused) {
            $this->addError('message', $refused->getMessage());

            return;
        }

        $this->sentCode = $ticket->code;
        $this->reset('message', 'category');
    }

    public function render(): View
    {
        return view('livewire.public.contact-form', ['categories' => TicketCategory::options()]);
    }
}
