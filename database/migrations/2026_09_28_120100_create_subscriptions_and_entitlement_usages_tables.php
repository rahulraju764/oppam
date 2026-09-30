<?php

declare(strict_types=1);

use App\Enums\Entitlement;
use App\Enums\SubscriptionSource;
use App\Enums\SubscriptionStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Subscriptions (minimal — Billing extends it in P5 with order/payment links) and the usage
| counters behind the PRD §7.3 limits. A profile with no ACTIVE, current subscription is on Free.
| entitlement_usages: one row per profile × entitlement × period; the unique key makes the atomic
| "increment only while under the limit" update the single source of truth (EntitlementService).
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained()->restrictOnDelete();
            $table->foreignUlid('plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default(SubscriptionStatus::Active->value);
            $table->string('source', 20)->default(SubscriptionSource::Paid->value);
            $table->timestamp('starts_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->timestamp('ends_at')->useCurrent();
            $table->timestamps();

            $table->index(['profile_id', 'status', 'ends_at']);   // "current plan" lookup
        });
        EnumCheck::add('subscriptions', 'status', SubscriptionStatus::class);
        EnumCheck::add('subscriptions', 'source', SubscriptionSource::class);

        Schema::create('entitlement_usages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained()->cascadeOnDelete();
            $table->string('entitlement', 40);
            $table->timestamp('period_start')->useCurrent();  // UTC instant the period began (explicit default: MariaDB)
            $table->timestamp('period_end')->nullable();      // null = lifetime counter
            $table->unsignedInteger('used')->default(0);
            $table->timestamps();

            $table->unique(['profile_id', 'entitlement', 'period_start']);
        });
        EnumCheck::add('entitlement_usages', 'entitlement', Entitlement::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usages');
        Schema::dropIfExists('subscriptions');
    }
};
