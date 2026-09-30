<?php

declare(strict_types=1);

use App\Support\Database\MasterColumns;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Master data (PRD §7.1 "Content & master data", A11). Read through the cached Masters service,
| never hardcoded. Parent links are explicit FKs (caste → religion, state → country,
| district → state); restrictOnDelete because rows are deactivated, never deleted, once used.
*/
return new class extends Migration
{
    /** Flat lists that share only the common shape. */
    private const FLAT = ['master_religions', 'master_stars', 'master_rasis', 'master_countries', 'master_education', 'master_occupations', 'master_mother_tongues'];

    public function up(): void
    {
        foreach (self::FLAT as $name) {
            Schema::create($name, fn (Blueprint $table) => MasterColumns::define($table));
        }

        Schema::create('master_castes', function (Blueprint $table): void {
            MasterColumns::define($table, uniqueCode: false);
            $table->foreignId('religion_id')->constrained('master_religions')->restrictOnDelete();
            $table->unique(['religion_id', 'code']);                // "OTHER" exists under every religion
            $table->index(['religion_id', 'is_active', 'sort_order']);   // caste dropdown by religion
        });

        Schema::create('master_states', function (Blueprint $table): void {
            MasterColumns::define($table, uniqueCode: false);
            $table->foreignId('country_id')->constrained('master_countries')->restrictOnDelete();
            $table->unique(['country_id', 'code']);
        });

        Schema::create('master_districts', function (Blueprint $table): void {
            MasterColumns::define($table, uniqueCode: false);
            $table->foreignId('state_id')->constrained('master_states')->restrictOnDelete();
            $table->unique(['state_id', 'code']);
        });

        Schema::create('master_income_bands', function (Blueprint $table): void {
            MasterColumns::define($table);
            // Annual income range in paise (never floats); null max = open-ended top band.
            $table->unsignedBigInteger('min_paise')->default(0);
            $table->unsignedBigInteger('max_paise')->nullable();
        });

        // Generic option lists: diet, smoking, drinking, complexion, body type, family type/status/values.
        Schema::create('master_options', function (Blueprint $table): void {
            MasterColumns::define($table, uniqueCode: false);
            $table->string('group', 40);
            $table->unique(['group', 'code']);
            $table->index(['group', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_options');
        Schema::dropIfExists('master_income_bands');
        Schema::dropIfExists('master_districts');
        Schema::dropIfExists('master_states');
        Schema::dropIfExists('master_castes');

        foreach (array_reverse(self::FLAT) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
