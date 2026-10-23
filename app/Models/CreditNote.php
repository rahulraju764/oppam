<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GstSplit;
use Database\Factories\CreditNoteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The GST credit note of a refund against an invoice (A06, P5.3). `number` (CN-2627-000001) is
 * gapless per Indian financial year in its own series, allocated inside the refund's side-effect
 * transaction; the GST split follows the invoice it credits. Never edited after issue.
 *
 * @property string $id
 * @property string $number
 * @property string $financial_year
 * @property int $sequence
 * @property string $refund_id
 * @property string $invoice_id
 * @property string $order_id
 * @property string $profile_id
 * @property Carbon $issued_at
 * @property GstSplit $gst_split
 * @property int $taxable_paise
 * @property int $gst_rate_bps
 * @property int $cgst_paise
 * @property int $sgst_paise
 * @property int $igst_paise
 * @property int $total_paise
 * @property-read Invoice $invoice
 * @property-read Refund $refund
 */
final class CreditNote extends Model
{
    /** @use HasFactory<CreditNoteFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'gst_split' => GstSplit::class,
            'sequence' => 'integer',
            'taxable_paise' => 'integer',
            'gst_rate_bps' => 'integer',
            'cgst_paise' => 'integer',
            'sgst_paise' => 'integer',
            'igst_paise' => 'integer',
            'total_paise' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Refund, $this> */
    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
