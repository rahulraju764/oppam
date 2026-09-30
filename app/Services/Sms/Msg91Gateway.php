<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Contracts\SmsGateway;
use App\Enums\OtpPurpose;
use App\Exceptions\Sms\SmsDeliveryFailed;
use App\ValueObjects\PhoneNumber;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * MSG91 "Send OTP" API (v5) with our own generated code and the DLT-approved OTP template
 * (PRD §4). The API takes the number and code as query parameters, so the request URL holds
 * the code: nothing derived from the URL or the HTTP client's exception text may ever reach a
 * log. SmsDeliveryFailed::$reason is therefore built from fixed text and the HTTP status only
 * (P1.1 review Blocker).
 */
final class Msg91Gateway implements SmsGateway
{
    private const ENDPOINT = 'https://control.msg91.com/api/v5/otp';

    private const FLOW_ENDPOINT = 'https://control.msg91.com/api/v5/flow';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $authKey,
        private readonly string $otpTemplateId,
        private readonly string $accountExistsTemplateId = '',
    ) {}

    public function sendOtp(PhoneNumber $to, string $code, OtpPurpose $purpose): void
    {
        if ($this->authKey === '' || $this->otpTemplateId === '') {
            throw SmsDeliveryFailed::because('MSG91 is not configured (MSG91_AUTH_KEY / MSG91_OTP_TEMPLATE_ID).');
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->withHeaders(['authkey' => $this->authKey])
                ->acceptJson()
                ->post(self::ENDPOINT.'?'.http_build_query([
                    'template_id' => $this->otpTemplateId,
                    'mobile' => ltrim($to->e164(), '+'),
                    'otp' => $code,
                ]));
        } catch (ConnectionException) {
            // Never $e->getMessage(): Guzzle appends the full request URI (code + number).
            throw SmsDeliveryFailed::because('MSG91 unreachable (connection error or timeout).');
        }

        if ($response->failed() || $response->json('type') !== 'success') {
            throw SmsDeliveryFailed::because('MSG91 refused the request (HTTP '.$response->status().').');
        }
    }

    /** Flow API with the DLT "account exists" template; the number travels in the JSON body. */
    public function sendAccountExistsNotice(PhoneNumber $to): void
    {
        if ($this->authKey === '' || $this->accountExistsTemplateId === '') {
            throw SmsDeliveryFailed::because('MSG91 is not configured (MSG91_AUTH_KEY / MSG91_ACCOUNT_EXISTS_TEMPLATE_ID).');
        }

        try {
            $response = $this->http
                ->timeout(10)
                ->withHeaders(['authkey' => $this->authKey])
                ->acceptJson()
                ->post(self::FLOW_ENDPOINT, [
                    'template_id' => $this->accountExistsTemplateId,
                    'recipients' => [['mobiles' => ltrim($to->e164(), '+')]],
                ]);
        } catch (ConnectionException) {
            throw SmsDeliveryFailed::because('MSG91 unreachable (connection error or timeout).');
        }

        if ($response->failed() || $response->json('type') !== 'success') {
            throw SmsDeliveryFailed::because('MSG91 refused the request (HTTP '.$response->status().').');
        }
    }
}
