<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * The human check on public forms (M12 contact, PRD A15 "reCAPTCHA/Turnstile"). Implementations:
 * TurnstileVerifier (Cloudflare) and DisabledCaptcha (local / testing only, refused in production).
 */
interface CaptchaVerifier
{
    /** Whether the form must show the widget and send a token. */
    public function enabled(): bool;

    /** The public widget key; null when disabled. */
    public function siteKey(): ?string;

    /** True only when the provider confirms the token. Fails closed: a provider outage is a no. */
    public function verify(?string $token, string $ip): bool;
}
