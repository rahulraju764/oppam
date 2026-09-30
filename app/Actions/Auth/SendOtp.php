<?php

declare(strict_types=1);

namespace App\Actions\Auth;

use App\Contracts\SmsGateway;
use App\Domain\Auth\OtpCode;
use App\Enums\OtpPurpose;
use App\Enums\SettingKey;
use App\Exceptions\Auth\OtpThrottled;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Cache\RateLimiter;

use function Illuminate\Support\defer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issues a one-time code by SMS (R-M01-2, PRD §8.2).
 *
 * Limits (A15 settings) are counted BEFORE anything is sent, for every request — including
 * requests for numbers with no account, which get no SMS ($recipient = null) but spend the same
 * quota, so the limits can't be used to tell registered numbers apart (R-M01-4):
 * - a per-IP daily ceiling first (a backstop: Indian carriers share IPs — docs/decisions.md
 *   2026-09-29), so an IP over its ceiling can't burn a victim number's quota;
 * - 30 s between two codes to one number (config), 3 per 15 min and 10 per day per number.
 *
 * A new code replaces any live code for the same number and purpose. The SMS itself is sent
 * AFTER the response (defer): the reply takes the same time whether or not the number has an
 * account, and a provider failure can't show up as a different message (R-M01-4). The code
 * stays in process memory — never in the jobs table — and a failure is logged without the code
 * or the number; the member uses "Resend".
 */
final class SendOtp
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly SmsGateway $sms,
    ) {}

    /** @throws OtpThrottled */
    public function handle(PhoneNumber $phone, OtpPurpose $purpose, string $ip, ?User $recipient): void
    {
        $this->enforceLimits($phone, $ip);

        if ($recipient === null) {
            return;
        }

        $code = OtpCode::generate();

        DB::transaction(function () use ($phone, $purpose, $ip, $recipient, $code): void {
            // One live code per number and purpose: older ones stop working.
            OtpChallenge::query()
                ->where('phone', $phone->e164())
                ->where('purpose', $purpose->value)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update(['expires_at' => now()]);

            $challenge = new OtpChallenge;
            $challenge->id = $challenge->newUniqueId();
            $challenge->forceFill([
                'user_id' => $recipient->id,
                'phone' => $phone->e164(),
                'purpose' => $purpose,
                'code_hash' => OtpCode::hash($challenge->id, $code, $this->key()),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(Settings::int(SettingKey::OtpTtlMinutes)),
                'ip_address' => $ip,
            ])->save();
        });

        $sms = $this->sms;

        defer(static function () use ($sms, $phone, $code, $purpose): void {
            try {
                $sms->sendOtp($phone, $code, $purpose);
            } catch (SmsDeliveryFailed $e) {
                // $reason is fixed text by contract: no code, no full number.
                Log::warning('OTP SMS failed', ['phone' => $phone->masked(), 'purpose' => $purpose->value, 'reason' => $e->reason]);
            }
        });
    }

    /** @throws OtpThrottled */
    private function enforceLimits(PhoneNumber $phone, string $ip): void
    {
        $ipKey = 'otp:ipday:'.$ip;

        // Count first, then compare: the increment is atomic, so parallel requests can't all pass
        // a check made before any of them counted (same pattern as AttemptAdminLogin).
        if ($this->limiter->hit($ipKey, 24 * 3600) > Settings::int(SettingKey::OtpMaxSendsPerIpPerDay)) {
            throw new OtpThrottled($this->limiter->availableIn($ipKey));
        }

        $number = sha1($phone->e164());
        $gapKey = 'otp:gap:'.$number;

        // The resend gap is checked before, and doesn't spend, the 15-minute / daily quota.
        if ($this->limiter->tooManyAttempts($gapKey, 1)) {
            throw new OtpThrottled($this->limiter->availableIn($gapKey));
        }

        $windows = [
            'otp:phone15:'.$number => [15 * 60, Settings::int(SettingKey::OtpMaxSendsPer15Minutes)],
            'otp:phoneday:'.$number => [24 * 3600, Settings::int(SettingKey::OtpMaxSendsPerPhonePerDay)],
        ];

        foreach ($windows as $key => [$decay, $max]) {
            if ($this->limiter->hit($key, $decay) > $max) {
                throw new OtpThrottled($this->limiter->availableIn($key));
            }
        }

        $this->limiter->hit($gapKey, (int) config('oppam.auth.otp_resend_seconds'));
    }

    private function key(): string
    {
        return (string) config('app.key');
    }
}
