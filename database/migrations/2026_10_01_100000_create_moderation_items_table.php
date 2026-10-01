<?php

declare(strict_types=1);

use App\Enums\ModerationItemType;
use App\Enums\ModerationStatus;
use App\Support\Database\EnumCheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| The A04 moderation queues (PRD §11 A04): one row per thing to review — a submitted profile
| (R-M02-2, P1.3), edited text fields of a live profile (R-M02-4, P1.5) or a photo (M11, P1.4).
| Queues read oldest-first with the paid lane first. The 15-minute soft claim (claimed_by /
| claimed_until) and the decision columns are written by the moderation screens in P1.6.
| reason_category is the reject template key (enum arrives with the A04 decisions, P1.6).
| `subject_id` points at the photo (media id) for PHOTO items; `fields` holds the edited
| field names and values for PROFILE_EDIT items.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 20);
            $table->foreignUlid('profile_id')->constrained()->cascadeOnDelete();
            $table->string('subject_id', 36)->nullable();
            $table->json('fields')->nullable();
            $table->string('status', 20)->default(ModerationStatus::Open->value);
            $table->boolean('is_priority')->default(false);          // paid-member lane
            $table->timestamp('submitted_at')->useCurrent();
            $table->foreignUlid('claimed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('claimed_until')->nullable();
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason_category', 40)->nullable();
            $table->string('reason_note', 1000)->nullable();        // shown to the member (R-M02-5)
            $table->timestamps();

            // Queue listing: pending items of a type, paid lane first, oldest first.
            $table->index(['type', 'status', 'is_priority', 'submitted_at']);
            // "Is this profile already waiting?" and the member's latest decision.
            $table->index(['profile_id', 'type', 'status']);
        });

        EnumCheck::add('moderation_items', 'type', ModerationItemType::class);
        EnumCheck::add('moderation_items', 'status', ModerationStatus::class);
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_items');
    }
};
