<?php

declare(strict_types=1);

use App\Actions\Auth\SendOtp;
use App\Actions\Auth\VerifyOtp;
use App\Enums\OtpPurpose;
use App\Enums\SettingKey;
use App\Exceptions\Auth\OtpInvalid;
use App\Exceptions\Auth\OtpThrottled;
use App\Models\OtpChallenge;
use App\Models\Setting;
use App\Support\Facades\Settings;
use App\ValueObjects\PhoneNumber;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Log;

/*
| P1.1 — R-M01-2: 6-digit codes, hashed at rest, 5-minute TTL, 3 wrong attempts invalidate,
| send limits (30 s gap, 3 per 15 min and 10 per day per number, per-IP daily ceiling).
*/

beforeEach(function (): void {
    $this->sms = fakeSms();
    $this->user = memberWithPhone('+919876543210');
    $this->phone = PhoneNumber::fromE164('+919876543210');
});

function sendCode(object $test, OtpPurpose $purpose = OtpPurpose::Login, string $ip = '10.0.0.1', bool $toUser = true): void
{
    app(SendOtp::class)->handle($test->phone, $purpose, $ip, $toUser ? $test->user : null);
}

it('R-M01-2: stores only a hash of the 6-digit code, with a 5-minute expiry', function (): void {
    sendCode($this);

    $code = $this->sms->lastCodeFor('+919876543210');
    $challenge = OtpChallenge::query()->sole();

    expect($code)->toMatch('/^\d{6}$/')
        ->and($challenge->getAttribute('code_hash'))->not->toBe($code)
        ->and($challenge->getAttribute('code_hash'))->not->toContain($code)
        ->and($challenge->expires_at->diffInSeconds(now()->addMinutes(5), true))->toBeLessThan(5)
        ->and($challenge->toArray())->not->toHaveKey('code_hash');   // hidden from serialisation
});

it('accepts the right code exactly once', function (): void {
    sendCode($this);
    $code = $this->sms->lastCodeFor('+919876543210');

    $challenge = app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $code);

    expect($challenge->consumed_at)->not->toBeNull()
        ->and(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $code))
        ->toThrow(OtpInvalid::class);
});

it('R-M01-2: expires the code after the TTL', function (): void {
    sendCode($this);
    $code = $this->sms->lastCodeFor('+919876543210');

    $this->travel(5)->minutes();
    $this->travel(1)->seconds();

    expect(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $code))
        ->toThrow(OtpInvalid::class, OtpInvalid::wrongOrExpired()->getMessage());
});

it('R-M01-2: three wrong attempts invalidate the code, even the right one afterwards', function (): void {
    sendCode($this);
    $code = $this->sms->lastCodeFor('+919876543210');
    $wrong = $code === '000000' ? '111111' : '000000';

    $verify = fn (string $c) => rescue(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $c), fn (Throwable $e) => $e->getMessage(), false);

    expect($verify($wrong))->toBe(OtpInvalid::wrongOrExpired()->getMessage())
        ->and($verify($wrong))->toBe(OtpInvalid::wrongOrExpired()->getMessage())
        ->and($verify($wrong))->toBe(OtpInvalid::tooManyAttempts()->getMessage())
        ->and($verify($code))->toBe(OtpInvalid::wrongOrExpired()->getMessage())
        ->and(OtpChallenge::query()->sole()->attempts)->toBe(3);
});

it('counts malformed codes as wrong attempts', function (): void {
    sendCode($this);

    expect(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, 'abc'))->toThrow(OtpInvalid::class)
        ->and(OtpChallenge::query()->sole()->attempts)->toBe(1);
});

it('never lets a code for one purpose satisfy another', function (): void {
    sendCode($this, OtpPurpose::Login);
    $code = $this->sms->lastCodeFor('+919876543210');

    expect(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::PasswordReset, $code))
        ->toThrow(OtpInvalid::class);
});

it('replaces an older live code when a new one is sent', function (): void {
    sendCode($this);
    $first = $this->sms->lastCodeFor('+919876543210');

    $this->travel(31)->seconds();
    sendCode($this);
    $second = $this->sms->lastCodeFor('+919876543210');

    if ($first !== $second) {
        expect(fn () => app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $first))->toThrow(OtpInvalid::class);
    }

    expect(app(VerifyOtp::class)->handle($this->phone, OtpPurpose::Login, $second)->consumed_at)->not->toBeNull();
});

it('refuses a second code within 30 seconds, with a retry-after', function (): void {
    sendCode($this);

    try {
        sendCode($this);
        $this->fail('Expected OtpThrottled');
    } catch (OtpThrottled $e) {
        expect($e->retryAfterSeconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(30);
    }

    expect($this->sms->countFor('+919876543210'))->toBe(1);
});

it('acceptance: the 4th code in 15 minutes is refused with a retry-after', function (): void {
    foreach (range(1, 3) as $_) {
        sendCode($this);
        $this->travel(31)->seconds();
    }

    try {
        sendCode($this);
        $this->fail('Expected OtpThrottled');
    } catch (OtpThrottled $e) {
        expect($e->retryAfterSeconds)->toBeGreaterThan(60)
            ->and($e->getMessage())->toContain('minutes');
    }

    expect($this->sms->countFor('+919876543210'))->toBe(3);
});

it('caps codes per number per day (A15 setting)', function (): void {
    Setting::factory()->keyed(SettingKey::OtpMaxSendsPerPhonePerDay, 4)->create();
    Settings::flush();

    foreach (range(1, 4) as $i) {
        sendCode($this);
        $this->travel($i % 3 === 0 ? 16 * 60 : 31)->seconds();
    }

    expect(fn () => sendCode($this))->toThrow(OtpThrottled::class)
        ->and($this->sms->countFor('+919876543210'))->toBe(4);
});

it('caps codes per IP per day across numbers (A15 setting)', function (): void {
    Setting::factory()->keyed(SettingKey::OtpMaxSendsPerIpPerDay, 10)->create();
    Settings::flush();

    foreach (range(0, 9) as $i) {
        app(SendOtp::class)->handle(PhoneNumber::fromParts('91', '90000000'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)), OtpPurpose::Login, '10.9.9.9', null);
    }

    expect(fn () => app(SendOtp::class)->handle(PhoneNumber::fromParts('91', '9000000099'), OtpPurpose::Login, '10.9.9.9', null))
        ->toThrow(OtpThrottled::class);
});

it('R-M01-4: spends the same quota for a number with no account, but sends nothing', function (): void {
    sendCode($this, toUser: false);

    expect($this->sms->sent)->toBe([])
        ->and(OtpChallenge::query()->count())->toBe(0)
        ->and(fn () => sendCode($this, toUser: false))->toThrow(OtpThrottled::class);   // 30 s gap applies too
});

it('R-M01-4: an SMS provider failure changes nothing the caller sees, and is logged without code or number', function (): void {
    $this->sms->failing = true;
    Log::spy();

    sendCode($this);   // no exception: the SMS goes out after the response (defer)

    $code = null;
    Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use (&$code): bool {
        $code = json_encode($context);

        return $message === 'OTP SMS failed';
    });

    expect($code)->not->toContain('9876543210')
        ->and($code)->toContain('+91 98')          // masked number only
        ->and(OtpChallenge::query()->count())->toBe(1);   // the code exists; Resend covers a lost SMS
});

it('R-M01-4: sends the SMS after the response, not inside it (same reply time for known and unknown numbers)', function (): void {
    // Undo tests/TestCase.php's withoutDefer(): collect deferred work like a real request does.
    $deferred = new DeferredCallbackCollection;
    $this->app->instance(DeferredCallbackCollection::class, $deferred);

    sendCode($this);

    expect($this->sms->sent)->toBe([])                        // nothing sent during the request
        ->and(OtpChallenge::query()->count())->toBe(1);

    $deferred->invoke();                                       // what the framework runs after the response

    expect($this->sms->countFor('+919876543210'))->toBe(1);
});
