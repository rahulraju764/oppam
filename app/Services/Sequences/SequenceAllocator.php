<?php

declare(strict_types=1);

namespace App\Services\Sequences;

use Illuminate\Support\Facades\DB;

/**
 * Allocates the next value of a named counter under a row lock, so two concurrent requests can
 * never receive the same value and no value is reused (profile codes, broker codes, gapless GST
 * invoice numbers). Call inside the caller's transaction when the value must roll back with it.
 */
final class SequenceAllocator
{
    public function next(string $name, int $start = 1): int
    {
        return DB::transaction(function () use ($name, $start): int {
            // Create the counter once; a concurrent creator loses the insert race harmlessly.
            DB::table('sequences')->insertOrIgnore([
                'name' => $name,
                'next_value' => $start,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $current = (int) DB::table('sequences')->where('name', $name)->lockForUpdate()->value('next_value');

            DB::table('sequences')->where('name', $name)->update([
                'next_value' => $current + 1,
                'updated_at' => now(),
            ]);

            return $current;
        });
    }
}
