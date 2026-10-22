<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
| ID verification (PRD M09, A05, F05 — P6.1).
| - verification_requests: one submission by a member. Only the LAST 4 characters of the number
|   and a keyed hash (duplicate checks) are stored — never the number. The decision, the masked
|   number and the hash stay after the 90-day purge; the name typed for the document goes with it.
| - verification_documents: the front / back files, encrypted on the private disk under a random
|   name; path + sha256 are cleared once the purge has verified the file is gone.
| ULID keys; rows go with their profile (cascade). Additive.
*/
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('profile_id')->constrained()->cascadeOnDelete();
            $table->string('doc_type', 20);
            $table->char('number_last4', 4);
            $table->char('number_hash', 64);
            $table->string('name_on_document', 100)->nullable();
            $table->string('status', 12);
            $table->json('flags')->nullable();
            $table->boolean('is_priority')->default(false);
            $table->timestamp('submitted_at')->useCurrent();   // explicit defaults: MariaDB implicit ON UPDATE (decisions.md)
            $table->timestamp('consented_at')->useCurrent();
            $table->foreignUlid('claimed_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('claimed_until')->nullable();
            $table->foreignUlid('decided_by_admin_id')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reject_reason', 20)->nullable();
            $table->string('member_note', 500)->nullable();
            $table->string('escalation_note', 500)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('documents_purged_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_priority', 'submitted_at']);     // the A05 queue order
            $table->index(['profile_id', 'submitted_at']);                 // member history, rapid resubmission
            $table->index('number_hash');                                  // duplicate-number flag
            $table->index(['documents_purged_at', 'decided_at']);          // the purge job
        });

        Schema::create('verification_documents', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('verification_request_id')->constrained()->cascadeOnDelete();
            $table->string('side', 5);
            $table->string('disk', 40);
            $table->string('path', 100)->nullable();
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64)->nullable();
            $table->timestamp('purged_at')->nullable();
            $table->timestamps();

            $table->unique(['verification_request_id', 'side']);
            $table->index('sha256');                                       // duplicate-document flag
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_documents');
        Schema::dropIfExists('verification_requests');
    }
};
