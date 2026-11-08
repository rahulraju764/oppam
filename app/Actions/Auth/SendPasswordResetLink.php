<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Enums\UserRole;
use App\Exceptions\Auth\LoginFailed;
use App\Models\User;
use App\Notifications\Auth\PasswordResetLink;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Forgot password — the email-link option (PRD §8.2 method 3; owner decisions 2026-11-08). The
 * answer is the same whether or not the address belongs to anyone (R-M01-4): a link is mailed only
 * when it is the VERIFIED email of an active member account. The link carries a random token;
 * `password_reset_tokens` keeps its SHA-256 (one per address, a new request replaces the old),
 * valid `oppam.auth.reset_link_minutes`. Limits per IP and per address apply to every request,
 * known address or not, so they reveal nothing either.
 */
final class SendPasswordResetLink
{
    public function __construct(private readonly RateLimiter $limiter) {}

    /** @throws LoginFailed too many requests from this IP or for this address */
    public function handle(string $email, string $ip): void
    {
        $email = Str::lower(trim($email));

        foreach ([
            'reset-link:ip:'.$ip => (int) config('oppam.auth.reset_links_per_ip_per_hour'),
            'reset-link:email:'.hash('sha256', $email) => (int) config('oppam.auth.reset_links_per_hour'),
        ] as $key => $max) {
            if ($this->limiter->tooManyAttempts($key, $max)) {
                throw LoginFailed::lockedOut($this->limiter->availableIn($key));
            }
        }
        $this->limiter->hit('reset-link:ip:'.$ip, 3600);
        $this->limiter->hit('reset-link:email:'.hash('sha256', $email), 3600);

        $member = User::query()->where('email', $email)->whereNotNull('email_verified_at')
            ->where('role', UserRole::Member->value)->first();
        if ($member === null || ! $member->isActive()) {
            return;
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->upsert(
            [['email' => $email, 'token' => hash('sha256', $token), 'created_at' => now()]],
            ['email'],
            ['token', 'created_at'],
        );

        $minutes = (int) config('oppam.auth.reset_link_minutes');
        Notification::route('mail', $email)->notify(new PasswordResetLink(route('password.reset.email', ['token' => $token]), $minutes));
    }
}
