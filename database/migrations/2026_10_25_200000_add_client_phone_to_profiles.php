<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| §11A B.6 / B.17 (P7.3, decisions 2026-10-25): a bureau-managed profile has no login until the
| client claims it, so its client mobile — the future claim key — lives on the profile. E.164,
| unique across managed profiles; CreateManagedProfile also refuses a number held by any user.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->string('client_phone', 16)->nullable()->unique()->after('managed_by_broker_id');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropUnique(['client_phone']);
            $table->dropColumn('client_phone');
        });
    }
};
