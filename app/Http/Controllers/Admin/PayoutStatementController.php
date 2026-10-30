<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Contracts\PayoutStatementRenderer;
use App\Enums\PayoutItemStatus;
use App\Models\Broker;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use Illuminate\Http\Response;

/**
 * GET admin /brokers/payouts/{PAY…}/statements/{BRK…} (A07, §11A B.10, `brokers.payout` on the
 * route): one bureau's statement for a paid line, rendered on demand. Anything else is a 404.
 */
final class PayoutStatementController
{
    public function __invoke(string $run, string $broker, PayoutStatementRenderer $renderer): Response
    {
        $runRow = BrokerPayoutRun::query()->where('code', $run)->first() ?? abort(404);
        $brokerRow = Broker::query()->where('code', $broker)->first() ?? abort(404);
        $item = BrokerPayoutItem::query()->where('run_id', $runRow->id)->where('broker_id', $brokerRow->id)
            ->where('status', PayoutItemStatus::Paid)->first() ?? abort(404);

        return self::pdf($renderer->render($item), $runRow->code.'-'.$brokerRow->code.'.pdf');
    }

    /** The statement as a private, never-cached PDF download (also used by the bureau's own download). */
    public static function pdf(string $bytes, string $filename): Response
    {
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
