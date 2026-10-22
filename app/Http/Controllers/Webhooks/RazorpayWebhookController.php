<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Billing\HandlePaymentWebhook;
use App\Exceptions\Billing\InvalidWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/razorpay (PRD §13 Webhooks, F04). No session, no CSRF: the HMAC signature over the
 * raw body is the authentication (HandlePaymentWebhook). A bad signature or body → 400 and nothing
 * is written; anything that throws → 500, so Razorpay retries (processing is idempotent).
 */
final class RazorpayWebhookController
{
    public function __invoke(Request $request, HandlePaymentWebhook $webhook): JsonResponse
    {
        try {
            $outcome = $webhook->handle(
                $request->getContent(),
                (string) $request->header('X-Razorpay-Signature', ''),
                $request->header('X-Razorpay-Event-Id'),
            );
        } catch (InvalidWebhook) {
            return response()->json(['status' => 'rejected'], 400);
        }

        return response()->json(['status' => 'ok', 'outcome' => $outcome]);
    }
}
