<?php

declare(strict_types=1);

namespace App\Services\Broker;

use App\Contracts\PayoutStatementRenderer;
use App\Enums\KycSubmissionStatus;
use App\Models\BrokerKyc;
use App\Models\BrokerPayoutItem;
use App\ValueObjects\Money;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * The per-bureau payout statement (§11A B.10 "orders (referral and managed conversions),
 * commission, clawbacks, TDS (if applicable), net paid, UTR reference") — resources/views/pdf/
 * payout-statement.blade.php via dompdf. Everything printed comes from the payout line and the
 * ledger rows it paid / clawed back; members appear by profile code only (R-M13-7); the bank
 * account by its last 4 digits.
 */
final class DompdfPayoutStatementRenderer implements PayoutStatementRenderer
{
    public function render(BrokerPayoutItem $item): string
    {
        $item->loadMissing([
            'run', 'broker.district',
            'referrals' => fn ($query) => $query->with(['profile' => fn ($profile) => $profile->withTrashed()->select(['id', 'code']), 'order:id,code'])->orderBy('earned_at'),
            'clawbacks' => fn ($query) => $query->with(['profile' => fn ($profile) => $profile->withTrashed()->select(['id', 'code']), 'order:id,code'])->orderBy('voided_at'),
        ]);
        $zone = (string) config('oppam.display_timezone');
        $kyc = BrokerKyc::query()->where('broker_id', $item->broker_id)->where('status', KycSubmissionStatus::Verified)->latest()->first();

        return Pdf::loadView('pdf.payout-statement', [
            'item' => $item,
            'run' => $item->run,
            'broker' => $item->broker,
            'kyc' => $kyc,
            'paidOn' => $item->paid_at?->copy()->timezone($zone)->format('j M Y'),
            'gross' => Money::paise($item->gross_paise),
            'clawback' => Money::paise($item->clawback_paise),
            'carriedIn' => $item->carried_in_paise,
            'tds' => Money::paise($item->tds_paise),
            'net' => $item->net(),
            'timezone' => $zone,
        ])->setPaper('a4')->output();
    }
}
