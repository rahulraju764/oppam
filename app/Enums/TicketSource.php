<?php

declare(strict_types=1);

namespace App\Enums;

/** Where a support ticket came from (PRD A10): the public contact form, in-app help, or email-in (later). */
enum TicketSource: string
{
    case ContactForm = 'CONTACT_FORM';
    case InApp = 'IN_APP';
    case Email = 'EMAIL';

    public function label(): string
    {
        return match ($this) {
            self::ContactForm => __('Contact form'),
            self::InApp => __('In-app help'),
            self::Email => __('Email'),
        };
    }
}
