<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\CaptchaVerifier;

/** CAPTCHA_DRIVER=none: local development and tests without the widget. Refused in production. */
final class DisabledCaptcha implements CaptchaVerifier
{
    public function enabled(): bool
    {
        return false;
    }

    public function siteKey(): ?string
    {
        return null;
    }

    public function verify(?string $token, string $ip): bool
    {
        return true;
    }
}
