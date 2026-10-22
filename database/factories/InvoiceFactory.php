<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GstSplit;
use App\Models\Invoice;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * An intra-state (CGST + SGST) invoice for a paid order, numbered in FY 2026-27.
 *
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $sequence = fake()->unique()->numberBetween(900000, 999999);

        return [
            'number' => sprintf('OPM-2627-%06d', $sequence),
            'financial_year' => '2627',
            'sequence' => $sequence,
            'order_id' => Order::factory()->paid(),
            'profile_id' => fn (array $attributes): string => (string) Order::query()->whereKey($attributes['order_id'])->value('profile_id'),
            'issued_at' => now(),
            'seller' => ['name' => 'Oppam Matrimony', 'gstin' => '', 'address' => 'Angamaly, Kerala', 'state_code' => 'KL', 'sac' => ''],
            'buyer' => ['name' => 'Test Member', 'code' => 'OPM10001', 'state' => 'Kerala', 'state_code' => 'KL'],
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
