<?php

declare(strict_types=1);

use App\Models\Profile;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/*
| Dusk member helpers (P1.1). Tests run against the LOCAL dev server and database with a
| throwaway number in the fake +91 99999 block; duskMemberCleanup() removes the account, its
| draft profile and the rate-limiter keys it used. OTPs are read from storage/logs/sms-*.log,
| where the local LogSmsGateway writes them (SMS_DRIVER=log). Loaded from tests/Pest.php.
*/

const DUSK_MEMBER_PHONE = '+919999900001';

function duskMemberCleanup(string $e164 = DUSK_MEMBER_PHONE): void
{
    $user = User::withTrashed()->where('phone', $e164)->first();

    if ($user !== null) {
        Profile::withTrashed()->where('user_id', $user->id)->forceDelete();
        $user->forceDelete();
    }

    $number = sha1($e164);
    foreach (['otp:gap:', 'otp:phone15:', 'otp:phoneday:'] as $prefix) {
        RateLimiter::clear($prefix.$number);
    }
    RateLimiter::clear('register:ip:127.0.0.1');
}

/** The last code the local SMS log shows for a masked number such as "+91 99•••••001". */
function duskLatestOtp(string $masked): ?string
{
    $files = glob(storage_path('logs/sms*.log')) ?: [];
    rsort($files);

    foreach ($files as $file) {
        $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES) ?: []);

        foreach ($lines as $line) {
            if (str_contains($line, $masked) && preg_match('/: (\d{6})\s*$/', $line, $match) === 1) {
                return $match[1];
            }
        }
    }

    return null;
}
