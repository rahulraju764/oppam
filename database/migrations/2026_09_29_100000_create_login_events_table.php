<?php

declare(strict_types=1);

use App\Enums\LoginMethod;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| Member / broker sign-ins (PRD §7.1 Identity "login_events", M01 "new-device alert"). One row per
| successful sign-in; device_hash is a SHA-256 of a random long-lived device cookie, so a device
| can be recognised without storing anything identifying. Read by A03 "Activity" later.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->string('method', 20);
            $table->char('device_hash', 64);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'device_hash']);
            $table->index(['user_id', 'created_at']);
        });

        EnumCheck::add('login_events', 'method', LoginMethod::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('login_events');
    }
};
