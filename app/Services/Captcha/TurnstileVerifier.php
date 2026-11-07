<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use App\Contracts\CaptchaVerifier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cloudflare Turnstile server-side check (siteverify). The secret never leaves the server. A token
 * is single-use; an outage or a timeout counts as a failed check, never as a pass.
 */
final class TurnstileVerifier implements CaptchaVerifier
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly string $siteKey,
        private readonly string $secret,
    ) {}

    public function enabled(): bool
    {
        return true;
    }

    public function siteKey(): string
    {
        return $this->siteKey;
    }

    public function verify(?string $token, string $ip): bool
    {
        if ($token === null || $token === '' || strlen($token) > 2048) {
            return false;
        }

        try {
            $response = Http::asForm()->timeout(5)->post(self::VERIFY_URL, [
                'secret' => $this->secret,
                'response' => $token,
                'remoteip' => $ip,
            ]);
        } catch (Throwable $failed) {
            Log::warning('Turnstile verification unavailable', ['error' => $failed::class]);

            return false;
        }

        return $response->successful() && $response->json('success') === true;
    }
}
