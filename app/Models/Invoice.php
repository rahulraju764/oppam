<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GstSplit;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The GST tax invoice of a paid order (M10, A06). `number` (OPM-2627-000001) is gapless per Indian
 * financial year — allocated inside the activation transaction, so a rolled-back activation never
 * uses one up. Seller and buyer details are copied at issue: a later address or settings change
 * never rewrites an invoice. The PDF lives on the private disk and is served only to its member.
 *
 * @property string $id
 * @property string $number
 * @property string $financial_year
 * @property int $sequence
 * @property string $order_id
 * @property string $profile_id
 * @property Carbon $issued_at
 * @property array{name: string, gstin: string, address: string, state_code: string, sac: string} $seller
 * @property array{name: string, code: string, state: string|null, state_code: string|null} $buyer
 * @property GstSplit $gst_split
 * @property int $taxable_paise
 * @property int $gst_rate_bps
 * @property int $cgst_paise
 * @property int $sgst_paise
 * @property int $igst_paise
 * @property int $total_paise
 * @property string|null $pdf_path
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'seller' => 'array',
            'buyer' => 'array',
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

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<Profile, $this> */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class)->withTrashed();
    }
}
