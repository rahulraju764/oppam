<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The numeric GST state code invoices print (Kerala 32), P5.1 review Minor. Nullable: MastersSeeder
| fills it for the seeded states; a state added in A11 without one prints its letter code.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_states', function (Blueprint $table): void {
            $table->string('gst_code', 2)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('master_states', function (Blueprint $table): void {
            $table->dropColumn('gst_code');
        });
    }
};
