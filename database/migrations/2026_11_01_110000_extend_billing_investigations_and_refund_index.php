<?php

declare(strict_types=1);

use App\Enums\InvestigationType;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| P5.3 review: an "investigate" row for a refund made outside Oppam (UNKNOWN_REFUND, so the type's
| CHECK is replaced), and an index for the A02 revenue figure on processed refunds.
*/
return new class extends Migration
{
    public function up(): void
    {
        $this->dropCheck();
        EnumCheck::add('billing_investigations', 'type', InvestigationType::class);

        Schema::table('refunds', function (Blueprint $table): void {
            $table->index(['status', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('refunds', function (Blueprint $table): void {
            $table->dropIndex(['status', 'processed_at']);
        });

        DB::table('billing_investigations')->where('type', 'UNKNOWN_REFUND')->delete();
        $this->dropCheck();
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE billing_investigations ADD CONSTRAINT '.self::CONSTRAINT." CHECK (`type` IN ('UNKNOWN_ORDER', 'AMOUNT_MISMATCH', 'EXTRA_PAYMENT', 'NOT_AT_GATEWAY', 'REFUND_FAILED', 'REFUND_STUCK'))");
        }
    }

    private const CONSTRAINT = 'chk_billing_investigations_type';

    private function dropCheck(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE billing_investigations DROP CONSTRAINT '.self::CONSTRAINT);
        }
    }
};
