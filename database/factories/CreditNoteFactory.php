<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GstSplit;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CreditNote> */
final class CreditNoteFactory extends Factory
{
    protected $model = CreditNote::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(900000, 999999);

        return [
            'number' => sprintf('CN-2627-%06d', $sequence),
            'financial_year' => '2627',
            'sequence' => $sequence,
            'refund_id' => Refund::factory()->processed(),
            'invoice_id' => fn (array $attributes): string => (string) (Invoice::query()->where('order_id', Refund::query()->whereKey($attributes['refund_id'])->value('order_id'))->value('id')
                ?? Invoice::factory()->create(['order_id' => Refund::query()->whereKey($attributes['refund_id'])->value('order_id')])->id),
            'order_id' => fn (array $attributes): string => (string) Refund::query()->whereKey($attributes['refund_id'])->value('order_id'),
            'profile_id' => fn (array $attributes): string => (string) Refund::query()->whereKey($attributes['refund_id'])->value('profile_id'),
            'issued_at' => now(),
            'gst_split' => GstSplit::IntraState,
            'taxable_paise' => 99_900,
            'gst_rate_bps' => 1800,
            'cgst_paise' => 8_991,
            'sgst_paise' => 8_991,
            'igst_paise' => 0,
            'total_paise' => 117_882,
        ];
    }
}
