<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\PlanCode;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Plans and what they allow (PRD §7.3, M10, A06). Money in paise. FREE is a real, never-
| purchasable row so the Free limits are editable like any other plan. Prices live per
| duration in plan_prices (monthly now; 3/6/12-month discounts are an open decision, §19 #7).
| plan_features: limit_value null = unlimited; flags use is_enabled.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            $table->string('badge', 40)->nullable();                // "Most Popular"
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_purchasable')->default(true);
            $table->boolean('is_active')->default(true);           // deactivate, never delete once referenced
            $table->json('display_features');                       // marketing bullet list (A06 "display features")
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        EnumCheck::add('plans', 'code', PlanCode::class);

        Schema::create('plan_prices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('duration_months');
            $table->unsignedInteger('price_paise');               // pre-GST
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['plan_id', 'duration_months']);
        });

        Schema::create('plan_features', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('plan_id')->constrained()->cascadeOnDelete();
            $table->string('entitlement', 40);
            $table->unsignedInteger('limit_value')->nullable();    // quotas: null = unlimited
            $table->boolean('is_enabled')->default(true);          // flags; a quota with is_enabled=false = 0
            $table->timestamps();
            $table->unique(['plan_id', 'entitlement']);
        });
        EnumCheck::add('plan_features', 'entitlement', Entitlement::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_features');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
