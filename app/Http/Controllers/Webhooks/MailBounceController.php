<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Comms\ApplyDeliveryReports;
use App\Contracts\MailBounceVerifier;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/mail/bounce — mail-provider bounce reports (A14, P8.3a). The configured
 * MailBounceVerifier checks the provider's signature (Mailgun HMAC now; SES/SNS refused until
 * built) before anything changes; an unverified request is a 403.
 */
final class MailBounceController extends Controller
{
    public function __invoke(Request $request, MailBounceVerifier $verifier, ApplyDeliveryReports $apply): JsonResponse
    {
        $bounces = $verifier->verify($request);

        if ($bounces === null) {
            return response()->json(['status' => 'rejected'], 403);
        }

        return response()->json(['status' => 'ok', 'updated' => $apply->mailEvents($bounces)]);
    }
}
