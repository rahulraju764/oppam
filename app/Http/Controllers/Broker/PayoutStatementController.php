<?php

declare(strict_types=1);

namespace App\Http\Controllers\Broker;

use App\Contracts\PayoutStatementRenderer;
use App\Domain\Broker\BureauGate;
use App\Enums\BureauPermission;
use App\Enums\PayoutItemStatus;
use App\Http\Controllers\Admin\PayoutStatementController as AdminStatement;
use App\Models\BrokerPayoutItem;
use App\Models\BrokerPayoutRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * GET /broker/earnings/statements/{PAY…} (§11A B.10 "per-broker PDF statement", Owner only —
 * `earnings.view`, R-M13-24): the caller's own bureau's paid line in that run, found from the
 * BureauGate staff row — never from the URL. Another bureau's run, or one where this bureau was
 * not paid, is a 404.
 */
final class PayoutStatementController
{
    public function __invoke(Request $request, string $run, BureauGate $gate, PayoutStatementRenderer $renderer): Response
    {
        /** @var User $user */
        $user = $request->user();
        $staff = $gate->authorize($user, BureauPermission::EarningsView);

        $runRow = BrokerPayoutRun::query()->where('code', $run)->first() ?? abort(404);
        $item = BrokerPayoutItem::query()->where('run_id', $runRow->id)->where('broker_id', $staff->broker_id)
            ->where('status', PayoutItemStatus::Paid)->first() ?? abort(404);

        return AdminStatement::pdf($renderer->render($item), $runRow->code.'.pdf');
    }
}
