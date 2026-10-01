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
        // Model by model, so the media library's delete hook removes photo rows + files too.
        Profile::withTrashed()->where('user_id', $user->id)->get()->each(fn (Profile $profile) => $profile->forceDelete());
        $user->forceDelete();
    }

    if ($user !== null) {
        RateLimiter::clear('media-upload:'.$user->id);
    }

    $number = sha1($e164);
    foreach (['otp:gap:', 'otp:phone15:', 'otp:phoneday:'] as $prefix) {
        RateLimiter::clear($prefix.$number);
    }
    // The dev server sees the browser as IPv4 or IPv6 loopback, depending on how localhost resolves.
    foreach (['127.0.0.1', '::1'] as $ip) {
        RateLimiter::clear('register:ip:'.$ip);
    }
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

/** A real 900×1100 JPEG for the upload tests (GD), written once to storage/app/dusk-photo.jpg. */
function duskPhotoFixture(): string
{
    $path = storage_path('app/dusk-photo.jpg');

    if (! is_file($path)) {
        $image = imagecreatetruecolor(900, 1100);
        imagefilledrectangle($image, 0, 0, 900, 1100, (int) imagecolorallocate($image, 224, 35, 73));
        imagefilledellipse($image, 450, 420, 360, 420, (int) imagecolorallocate($image, 250, 220, 200));
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    return $path;
}
