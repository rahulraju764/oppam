<?php

declare(strict_types=1);

namespace App\Data\Support;

/** What the public contact form sends (M12). The honeypot and captcha token travel with it. */
final readonly class ContactRequestData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $category,
        public string $message,
        public string $honeypot,
        public ?string $captchaToken,
    ) {}
}
