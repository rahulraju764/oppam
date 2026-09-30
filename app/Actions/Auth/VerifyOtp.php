<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Domain\Auth\OtpCode;
use App\Enums\OtpPurpose;
use App\Enums\SettingKey;
use App\Exceptions\Auth\OtpInvalid;
use App\Models\OtpChallenge;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Facades\DB;

/**
 * Checks a one-time code (R-M01-2): the latest live code for this number AND purpose (a login code
 * never resets a password), not expired, not used. Each wrong code counts; the Nth wrong code
 * (OtpMaxAttempts, default 3) kills it. A right code is consumed, so it works exactly once.
 * Attempts are counted under a row lock and committed even when the code is wrong.
 */
final class VerifyOtp
{
    /** @throws OtpInvalid */
    public function handle(PhoneNumber $phone, OtpPurpose $purpose, string $code): OtpChallenge
    {
        $code = trim($code);
        $maxAttempts = Settings::int(SettingKey::OtpMaxAttempts);

        /** @var array{0: OtpChallenge|null, 1: string} $result */
        $result = DB::transaction(function () use ($phone, $purpose, $code, $maxAttempts): array {
            $challenge = OtpChallenge::query()
                ->where('phone', $phone->e164())
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->where('attempts', '<', $maxAttempts)
                ->latest('created_at')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($challenge === null) {
                return [null, 'missing'];
            }

            if (OtpCode::isWellFormed($code) && OtpCode::matches($challenge->id, $code, (string) $challenge->getAttribute('code_hash'), (string) config('app.key'))) {
                $challenge->forceFill(['consumed_at' => now()])->save();

                return [$challenge, 'ok'];
            }

            $challenge->forceFill(['attempts' => $challenge->attempts + 1])->save();

            return [null, $challenge->attempts >= $maxAttempts ? 'exhausted' : 'wrong'];
        });

        return match ($result[1]) {
            'ok' => $result[0] ?? throw OtpInvalid::wrongOrExpired(),
            'exhausted' => throw OtpInvalid::tooManyAttempts(),
            default => throw OtpInvalid::wrongOrExpired(),
        };
    }
}
