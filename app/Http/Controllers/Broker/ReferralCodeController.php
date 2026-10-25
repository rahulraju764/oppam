<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Domain\Broker\BrokerCodeLookup;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /broker/validate?code=BRK1042 (B.5 inline validation): `{valid, name, district}` only — the
 * bureau's display name and district, never its phone, email or owner. An unknown and an inactive
 * code answer the same `valid: false`. Public and throttled (route); not cached by browsers.
 */
final class ReferralCodeController extends Controller
{
    public function __invoke(Request $request, BrokerCodeLookup $codes): JsonResponse
    {
        $code = $request->query('code');

        return response()->json($codes->hint(is_string($code) ? $code : null)->toArray())
            ->header('Cache-Control', 'no-store');
    }
}
