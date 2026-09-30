<?php

declare(strict_types=1);

use App\Enums\OtpPurpose;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| One-time codes (M01, R-M01-2): 6 digits, stored HASHED, 5-minute TTL, 3 wrong attempts
| invalidate it. Send limits (3 per 15 min per phone, 10/day per IP) are counted from these rows
| and RateLimiter in P1.1.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 16);
            $table->string('purpose', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at')->useCurrent();   // explicit default: see docs/decisions.md (MariaDB implicit ON UPDATE)
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['phone', 'purpose', 'created_at']);    // latest live code + send-limit count
            $table->index(['ip_address', 'created_at']);          // per-IP daily limit
        });

        EnumCheck::add('otp_challenges', 'purpose', OtpPurpose::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
