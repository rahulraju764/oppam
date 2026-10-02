<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| A04 "duplicate identity" pre-flag (P1.6): looks for another profile with the same date of birth
| and name. Without an index led by dob it was a full scan of profiles.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->index(['dob', 'last_name', 'first_name'], 'profiles_identity_index');
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table): void {
            $table->dropIndex('profiles_identity_index');
        });
    }
};
