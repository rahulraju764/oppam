<?php

declare(strict_types=1);

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\Services\Sms\LogSmsGateway;
use App\Services\Sms\Msg91Gateway;
use App\ValueObjects\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| P1.1 — SmsGateway (PRD §4): MSG91 in production, log locally, bound by SMS_DRIVER.
*/

function bindSmsDriver(string $driver): SmsGateway
{
    config(['oppam.sms.driver' => $driver]);
    app()->forgetInstance(SmsGateway::class);

    return app(SmsGateway::class);
}

it('binds the gateway from SMS_DRIVER', function (): void {
    expect(bindSmsDriver('log'))->toBeInstanceOf(LogSmsGateway::class)
        ->and(bindSmsDriver('msg91'))->toBeInstanceOf(Msg91Gateway::class);
});

it('refuses an unknown driver', function (): void {
    expect(fn () => bindSmsDriver('carrier-pigeon'))->toThrow(RuntimeException::class);
});

it('refuses the log driver in production (it writes codes to a file)', function (): void {
    app()->detectEnvironment(fn () => 'production');

    try {
        expect(fn () => bindSmsDriver('log'))->toThrow(RuntimeException::class, 'not allowed in production');
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('sends the code through the MSG91 OTP API with the DLT template', function (): void {
    config(['services.msg91.auth_key' => 'test-key', 'services.msg91.otp_template_id' => 'tpl-1']);
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'success'])]);

    bindSmsDriver('msg91')->sendOtp(PhoneNumber::fromParts('91', '9876543210'), '123456', OtpPurpose::Login);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('authkey', 'test-key')
        && str_contains($request->url(), 'template_id=tpl-1')
        && str_contains($request->url(), 'mobile=919876543210')
        && str_contains($request->url(), 'otp=123456'));
});

it('reports MSG91 refusals and outages as SmsDeliveryFailed', function (mixed $response): void {
    config(['services.msg91.auth_key' => 'test-key', 'services.msg91.otp_template_id' => 'tpl-1']);
    Http::fake(['control.msg91.com/*' => $response]);

    expect(fn () => bindSmsDriver('msg91')->sendOtp(PhoneNumber::fromParts('91', '9876543210'), '123456', OtpPurpose::Login))
        ->toThrow(SmsDeliveryFailed::class);
})->with([
    'error type' => fn () => Http::response(['type' => 'error', 'message' => 'invalid template']),
    'server error' => fn () => Http::response('down', 503),
]);

it('refuses to send when MSG91 is not configured', function (): void {
    config(['services.msg91.auth_key' => '', 'services.msg91.otp_template_id' => '']);

    expect(fn () => bindSmsDriver('msg91')->sendOtp(PhoneNumber::fromParts('91', '9876543210'), '123456', OtpPurpose::Login))
        ->toThrow(SmsDeliveryFailed::class);
});

it('P1.1 review Blocker: a MSG91 timeout never puts the code or the number in the failure reason', function (): void {
    config(['services.msg91.auth_key' => 'test-key', 'services.msg91.otp_template_id' => 'tpl-1']);
    Http::fake(fn () => throw new ConnectionException(
        'cURL error 28: Operation timed out for https://control.msg91.com/api/v5/otp?template_id=tpl-1&mobile=919876543210&otp=654321',
    ));

    try {
        bindSmsDriver('msg91')->sendOtp(PhoneNumber::fromParts('91', '9876543210'), '654321', OtpPurpose::Login);
        $this->fail('Expected SmsDeliveryFailed');
    } catch (SmsDeliveryFailed $e) {
        expect($e->reason)->not->toContain('654321')
            ->and($e->reason)->not->toContain('9876543210')
            ->and($e->getMessage())->not->toContain('654321')
            ->and($e->getPrevious())->toBeNull();   // the Guzzle text isn't carried along either
    }
});

it('never puts the code in the reason when MSG91 refuses', function (): void {
    config(['services.msg91.auth_key' => 'test-key', 'services.msg91.otp_template_id' => 'tpl-1']);
    Http::fake(['control.msg91.com/*' => Http::response(['type' => 'error', 'message' => 'bad otp 654321'], 400)]);

    try {
        bindSmsDriver('msg91')->sendOtp(PhoneNumber::fromParts('91', '9876543210'), '654321', OtpPurpose::Login);
        $this->fail('Expected SmsDeliveryFailed');
    } catch (SmsDeliveryFailed $e) {
        expect($e->reason)->toBe('MSG91 refused the request (HTTP 400).');
    }
});

it('P1.1 review: Pulse never records MSG91 request URLs (they hold the code and number)', function (): void {
    $recorder = app(Laravel\Pulse\Recorders\SlowOutgoingRequests::class);
    $shouldIgnore = (new ReflectionMethod($recorder, 'shouldIgnore'))->getClosure($recorder);

    expect($shouldIgnore('https://control.msg91.com/api/v5/otp?template_id=t&mobile=919876543210&otp=654321'))->toBeTrue()
        ->and($shouldIgnore('https://api.razorpay.com/v1/orders'))->toBeFalse();
});
