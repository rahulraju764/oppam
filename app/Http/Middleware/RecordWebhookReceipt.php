<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\WebhookOutcome;
use App\Enums\WebhookProvider;
use App\Models\WebhookReceipt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A12 system health — one webhook_receipts row per inbound webhook request: provider (from the
 * route name), outcome (the controller's own rejection tag, else the status code), status and
 * duration. Nothing of the request itself — no body, headers or IP — is kept. Throttled (429)
 * requests are not recorded, so a flood can't be turned into a flood of writes. Recording never
 * changes the response: a failed write is only reported.
 */
final class RecordWebhookReceipt
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        /** @var Response $response */
        $response = $next($request);

        $provider = WebhookProvider::forRoute($request->route()?->getName());
        $status = $response->getStatusCode();

        if ($provider !== null && $status !== Response::HTTP_TOO_MANY_REQUESTS) {
            rescue(function () use ($request, $provider, $status, $started): void {
                $tag = $request->attributes->get(WebhookOutcome::REQUEST_ATTRIBUTE);

                (new WebhookReceipt)->forceFill([
                    'provider' => $provider,
                    'outcome' => WebhookOutcome::fromResponse($status, $tag instanceof WebhookOutcome ? $tag : null),
                    'http_status' => $status,
                    'duration_ms' => (int) min(4_294_967_295, intdiv(hrtime(true) - $started, 1_000_000)),
                    'received_at' => now(),
                ])->save();
            });
        }

        return $response;
    }
}
