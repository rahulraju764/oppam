<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Data\Content\SeoData;
use App\Data\Content\SiteContactData;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Contact page (template contact.php, PRD M12). The form is <livewire:public.contact-form>: it
 * opens an A10 support ticket (P6.2); Turnstile and the A08 inbox come with P8.1. Contact details
 * come from SiteContactData (A15 settings) — one source for this page and the footer.
 */
final class Contact extends Component
{
    public function render(): View
    {
        return view('livewire.public.contact', [
            'site' => SiteContactData::current(),
        ])->layout('layouts::public', ['seo' => new SeoData(
            title: 'Contact Us | Oppam Matrimony',
            description: 'Questions about registration, membership or your profile? Contact the Oppam Matrimony support team.',
            keywords: 'Contact Oppam Matrimony, Kerala Matrimony Support, Matrimony Help Kerala',
            ogTitle: 'Contact Oppam Matrimony',
        )]);
    }
}
