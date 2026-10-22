<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Domain\Billing\FinancialYear;
use App\Enums\GstSplit;
use App\Enums\SettingKey;
use App\Models\Invoice;
use App\Models\Masters\District;
use App\Models\Order;
use App\Models\Profile;
use App\Services\Sequences\SequenceAllocator;
use App\Support\Facades\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The GST invoice of a paid order (M10, A06 "gapless GST invoice numbering"). Called by
 * ActivateSubscription INSIDE its transaction: the number comes from a row-locked counter per
 * financial year, so a rolled-back activation releases its number and the series has no gaps.
 * Number: OPM-<FY>-<6 digits> (≤ 16 characters, GST rule 46), e.g. OPM-2627-000001.
 * Place of supply = the member's state on record (district → state); unknown → the seller's state.
 * Seller and buyer details are copied now and never re-read.
 */
final class IssueInvoice
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly FinancialYear $financialYear,
    ) {}

    public function handle(Order $order, Profile $profile, CarbonImmutable $issuedAt): Invoice
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Invoices are issued inside the activation transaction.');
        }

        $year = $this->financialYear->of($issuedAt);
        $sequence = $this->sequences->next('invoice:'.$year);

        $seller = [
            'name' => Settings::string(SettingKey::BillingSellerName),
            'gstin' => Settings::string(SettingKey::BillingSellerGstin),
            'address' => Settings::string(SettingKey::BillingSellerAddress),
            'state_code' => Settings::string(SettingKey::BillingSellerStateCode),
            'sac' => Settings::string(SettingKey::BillingSacCode),
        ];

        $state = $profile->district_id === null ? null : District::query()->with('state')->find($profile->district_id)?->state;
        $split = $state === null || $state->code === $seller['state_code'] ? GstSplit::IntraState : GstSplit::InterState;
        $central = intdiv($order->gst_paise, 2);

        $invoice = new Invoice;
        $invoice->forceFill([
            'number' => sprintf('OPM-%s-%06d', $year, $sequence),
            'financial_year' => $year,
            'sequence' => $sequence,
            'order_id' => $order->id,
            'profile_id' => $profile->id,
            'issued_at' => $issuedAt,
            'seller' => $seller,
            'buyer' => [
                'name' => trim($profile->first_name.' '.$profile->last_name),
                'code' => $profile->code,
                'state' => $state?->label,
                'state_code' => $state?->code,
            ],
            'gst_split' => $split,
            'taxable_paise' => $order->taxable_paise,
            'gst_rate_bps' => $order->gst_rate_bps,
            'cgst_paise' => $split === GstSplit::IntraState ? $central : 0,
            'sgst_paise' => $split === GstSplit::IntraState ? $order->gst_paise - $central : 0,
            'igst_paise' => $split === GstSplit::InterState ? $order->gst_paise : 0,
            'total_paise' => $order->total_paise,
        ])->save();

        return $invoice;
    }
}
