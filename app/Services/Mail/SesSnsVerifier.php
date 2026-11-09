<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Contracts\MailBounceVerifier;
use Illuminate\Http\Request;

/**
 * Amazon SES bounces arrive through SNS, whose messages are signed with an AWS certificate. Not
 * built yet: the mail provider isn't chosen (outside task). Until then every request is refused —
 * fails closed, never trusts an unsigned bounce (decisions 2026-11-08 P8.3a).
 */
final class SesSnsVerifier implements MailBounceVerifier
{
    public function verify(Request $request): ?array
    {
        return null;
    }
}
