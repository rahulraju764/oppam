<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Comms\ApplyDeliveryReports;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /webhooks/msg91/dlr — MSG91 delivery reports (A14, P8.3a). MSG91 doesn't sign them, so the
 * configured URL carries a shared secret (`?secret=` or the X-Webhook-Secret header), compared in
 * constant time; no secret configured → every request refused (fails closed). Body: a list of
 * {requestId, report: [{status}]} (or one such object). Only the delivery log changes.
 */
final class Msg91DeliveryReportController extends Controller
{
    public function __invoke(Request $request, ApplyDeliveryReports $apply): JsonResponse
    {
        $secret = (string) config('oppam.comms.msg91_dlr_secret');
        $given = (string) ($request->header('X-Webhook-Secret') ?? $request->query('secret', ''));

        if ($secret === '' || ! hash_equals($secret, $given)) {
            return response()->json(['status' => 'rejected'], 403);
        }

        $body = json_decode($request->getContent(), true);
        $items = is_array($body) ? (array_is_list($body) ? $body : [$body]) : [];
        $reports = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $requestId = $item['requestId'] ?? $item['request_id'] ?? null;
            foreach (is_array($item['report'] ?? null) ? $item['report'] : [$item] as $line) {
                $status = is_array($line) ? ($line['status'] ?? null) : null;
                if (is_string($requestId) && $requestId !== '' && (is_string($status) || is_int($status))) {
                    $reports[] = ['request_id' => $requestId, 'status' => (string) $status];
                }
            }
        }

        return response()->json(['status' => 'ok', 'updated' => $apply->sms(array_slice($reports, 0, 500))]);
    }
}
